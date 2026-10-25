<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Appartment;
use App\Entity\ReservationStatus;
use App\Entity\User;
use App\Service\ReservationObject;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Covers HTTP validation of reservation periods before availability queries run.
 */
final class ReservationPeriodControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testExcessiveReservationPeriodReturnsTranslated422(): void
    {
        $client = static::createClient();
        $client->loginUser($this->getAdminUser(), 'main');

        $client->request('POST', '/reservation/appartments/available/get', [
            'from' => '2026-09-12',
            'end' => '4026-09-13',
            'object' => 'all',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(
            'Der gewählte Reservierungszeitraum ist zu lang.',
            trim((string) $client->getResponse()->getContent()),
        );
    }

    public function testReservationSelectionSurvivesSessionReloadsAndKeepsSparseKeys(): void
    {
        $client = static::createClient();
        $client->loginUser($this->getAdminUser(), 'main');
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        $rooms = $manager->getRepository(Appartment::class)->findBy(['active' => true], ['id' => 'ASC'], 2);
        self::assertCount(2, $rooms);
        $roomIds = array_map(static fn (Appartment $room): string => sprintf('%03d', $room->getId()), $rooms);
        $status = $manager->getRepository(ReservationStatus::class)->findOneBy(['code' => null], ['id' => 'ASC']);
        self::assertInstanceOf(ReservationStatus::class, $status);
        $statusId = sprintf('%03d', $status->getId());
        $start = new \DateTimeImmutable('+1200 days');
        $end = $start->modify('+2 days');

        $client->request('POST', '/reservation/appartments/selectable/add/to/reservation', [
            'createNewReservation' => 'true',
            'status' => $statusId,
            'apartments' => array_map(static fn (string $id): array => [
                'id' => $id,
                'from' => $start->format('Y-m-d'),
                'end' => $end->format('Y-m-d'),
            ], $roomIds),
        ]);

        self::assertResponseIsSuccessful();
        $selection = $this->selection($client);
        self::assertSame([0, 1], array_keys($selection));
        self::assertSame($roomIds, array_map(static fn (ReservationObject $draft): int|string => $draft->getAppartmentId(), $selection));
        self::assertSame($statusId, $selection[1]->getReservationStatus());

        $client->request('POST', '/reservation/appartments/remove/from/reservation', ['appartmentid' => '0']);

        self::assertResponseIsSuccessful();
        self::assertSame([1], array_keys($this->selection($client)));

        $client->request('POST', '/reservation/appartments/modify/options', [
            'appartmentid' => '1',
            'persons' => '1',
            'status' => $statusId,
            'guestCounts' => '{}',
        ]);

        self::assertResponseIsSuccessful();
        $selection = $this->selection($client);
        self::assertSame([1], array_keys($selection));
        self::assertSame($roomIds[1], $selection[1]->getAppartmentId());
        self::assertSame($start->format('Y-m-d'), $selection[1]->getStart());
        self::assertSame($end->format('Y-m-d'), $selection[1]->getEnd());
        self::assertSame(1, $selection[1]->getPersons());
        self::assertSame($statusId, $selection[1]->getReservationStatus());
        self::assertNull($selection[1]->getCustomerId());
    }

    /** @return array<ReservationObject> */
    private function selection(KernelBrowser $client): array
    {
        $session = static::getContainer()->get('session.factory')->createSession();
        $cookie = $client->getCookieJar()->get($session->getName());
        self::assertNotNull($cookie);
        $session->setId($cookie->getValue());
        $session->start();
        $selection = $session->get('reservationInCreation');
        $session->save();
        self::assertIsArray($selection);
        foreach ($selection as $draft) {
            self::assertInstanceOf(ReservationObject::class, $draft);
        }

        return $selection;
    }

    private function getAdminUser(): User
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get('doctrine')->getManager();
        $user = $em->getRepository(User::class)->findOneBy(['username' => 'test-admin']);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
