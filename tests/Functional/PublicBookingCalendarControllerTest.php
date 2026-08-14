<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Appartment;
use App\Entity\Enum\PublicBookingMode;
use App\Entity\Enum\PublicBookingTheme;
use App\Entity\OnlineBookingConfig;
use App\Service\OnlineBookingConfigService;
use App\Service\PublicBookingAbuseProtectionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Contract of the anonymous booking-calendar endpoint, with an emphasis on what it
 * must refuse and what it must not disclose.
 */
final class PublicBookingCalendarControllerTest extends WebTestCase
{
    public function testCalendarReturnsNightBooleansForAReleasedRoom(): void
    {
        $client = self::createClient();
        $room = $this->getAnyRoom();
        $this->useConfig($this->createCalendarConfig());

        $client->request('GET', '/book/calendar-data?room='.$room->getUuid().'&from='.date('Y-m').'&months=1');

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertSame(['room', 'from', 'toExclusive', 'nights'], array_keys($payload));
        self::assertSame((string) $room->getUuid(), $payload['room']);
        self::assertNotEmpty($payload['nights']);
        foreach ($payload['nights'] as $night => $free) {
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $night);
            self::assertContains($free, [0, 1], 'The calendar must expose booleans only.');
        }
    }

    public function testCalendarIsNotFoundForAnUnknownRoom(): void
    {
        $client = self::createClient();
        $this->useConfig($this->createCalendarConfig());

        $client->request('GET', '/book/calendar-data?room='.Uuid::v4().'&from='.date('Y-m').'&months=1');

        self::assertResponseStatusCodeSame(404);
    }

    public function testCalendarIsNotFoundWhenDisabled(): void
    {
        $client = self::createClient();
        $room = $this->getAnyRoom();
        $config = $this->createCalendarConfig();
        $config->setMode(PublicBookingMode::SEARCH);
        $this->useConfig($config);

        $client->request('GET', '/book/calendar-data?room='.$room->getUuid().'&from='.date('Y-m').'&months=1');

        self::assertResponseStatusCodeSame(404);
    }

    public function testCalendarIsNotFoundForTheClassicTheme(): void
    {
        $client = self::createClient();
        $room = $this->getAnyRoom();
        $config = $this->createCalendarConfig();
        $config->setTheme(PublicBookingTheme::CLASSIC);
        $this->useConfig($config);

        $client->request('GET', '/book/calendar-data?room='.$room->getUuid().'&from='.date('Y-m').'&months=1');

        self::assertResponseStatusCodeSame(404);
    }

    public function testCalendarRejectsAMalformedWindow(): void
    {
        $client = self::createClient();
        $room = $this->getAnyRoom();
        $this->useConfig($this->createCalendarConfig());

        $client->request('GET', '/book/calendar-data?room='.$room->getUuid().'&from=whenever&months=1');

        self::assertResponseStatusCodeSame(404);
    }

    public function testCalendarNeverReachesBeyondTheBookingHorizon(): void
    {
        $client = self::createClient();
        $room = $this->getAnyRoom();
        $config = $this->createCalendarConfig();
        $config->setBookingHorizonMonths(1);
        $this->useConfig($config);

        $client->request('GET', '/book/calendar-data?room='.$room->getUuid().'&from='.date('Y-m').'&months=3');

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);

        $horizon = (new \DateTimeImmutable('today'))->modify('+1 month');
        self::assertLessThanOrEqual($horizon->format('Y-m-d'), $payload['toExclusive']);
    }

    /** Build an enabled config with the calendar switched on. */
    private function createCalendarConfig(): OnlineBookingConfig
    {
        $config = new OnlineBookingConfig();
        $config->setEnabled(true);
        $config->setBookingMode(OnlineBookingConfig::BOOKING_MODE_INQUIRY);
        $config->setTheme(PublicBookingTheme::MODERN);
        $config->setMode(PublicBookingMode::CALENDAR);

        return $config;
    }

    /** Serve the given config and disable rate limiting for the assertions below. */
    private function useConfig(OnlineBookingConfig $config): void
    {
        $configService = $this->createStub(OnlineBookingConfigService::class);
        $configService->method('getConfig')->willReturn($config);
        $configService->method('getAllowedRoomIds')->willReturn(
            array_map(static fn (Appartment $room) => $room->getId(), $this->getRooms())
        );
        $configService->method('getAllowedSubsidiaryIds')->willReturn(
            array_values(array_unique(array_map(static fn (Appartment $room) => $room->getObject()->getId(), $this->getRooms())))
        );

        self::getContainer()->set(OnlineBookingConfigService::class, $configService);
        self::getContainer()->set(
            PublicBookingAbuseProtectionService::class,
            $this->createStub(PublicBookingAbuseProtectionService::class)
        );
    }

    /** @return Appartment[] */
    private function getRooms(): array
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get('doctrine')->getManager();

        return $em->getRepository(Appartment::class)->findBy(['active' => true]);
    }

    private function getAnyRoom(): Appartment
    {
        $rooms = array_values(array_filter(
            $this->getRooms(),
            static fn (Appartment $room): bool => true !== $room->isMultipleOccupancy()
        ));

        if ([] === $rooms) {
            self::fail('No usable room found in the test database.');
        }

        return $rooms[0];
    }
}
