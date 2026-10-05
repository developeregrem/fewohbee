<?php

declare(strict_types=1);

namespace App\Tests\Unit\GuestCheckIn;

use App\Entity\Appartment;
use App\Entity\Customer;
use App\Entity\CustomerAddresses;
use App\Entity\GuestCheckIn;
use App\Entity\Reservation;
use App\Entity\ReservationStatus;
use App\Repository\GuestCheckInRepository;
use App\Service\GuestCheckIn\GuestCheckInApplyService;
use App\Service\GuestCheckIn\GuestCheckInConfigService;
use App\Service\GuestCheckIn\GuestCheckInExistingGuestMatcher;
use App\Service\GuestCheckIn\GuestCheckInExtrasService;
use App\Service\GuestCheckIn\GuestCheckInInvitationService;
use App\Service\GuestCheckIn\GuestCheckInPolicy;
use App\Service\GuestCheckIn\GuestCheckInReviewService;
use App\Service\PublicUrlService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\Translation\TranslatorInterface;

final class GuestCheckInReviewServiceTest extends TestCase
{
    public function testChangesAreShownPerPossibleRecord(): void
    {
        $booker = $this->customer(1, 'Anna', 'Müller');
        $address = (new CustomerAddresses())->setType(GuestCheckInApplyService::ADDRESS_TYPE_PRIVATE)->setCity('Berlin')->setZip('10115');
        $booker->addCustomerAddress($address);
        $reservation = $this->reservation($booker, 2);

        $review = $this->review($reservation, ['firstname' => 'Anna', 'lastname' => 'Müller', 'birthday' => '1980-05-01', 'address' => ['city' => 'Wien', 'zip' => '10115']]);

        $main = $review['persons'][0];
        self::assertSame('booker', $main['defaultTarget']);
        $toBooker = $this->option($main, 'booker');
        self::assertFalse($toBooker['overwrites']);
        self::assertSame(['customer.city'], array_column($toBooker['changes']['changed'], 'label'));
        self::assertSame('Berlin', $toBooker['changes']['changed'][0]['old']);
        self::assertSame(['customer.birthday'], array_column($toBooker['changes']['new'], 'label'));
        self::assertContains('customer.zip', array_column($toBooker['changes']['same'], 'label'));
        $toNew = $this->option($main, 'new');
        self::assertSame([], $toNew['changes']['changed'], 'A new record has nothing to change.');
        self::assertCount(5, $toNew['changes']['new']);
    }

    public function testDifferentPersonIsNeitherPreselectedNorSimple(): void
    {
        $booker = $this->customer(1, 'Anna', 'Müller');
        $reservation = $this->reservation($booker, 1);
        $reservation->addCustomer($booker);

        $review = $this->review($reservation, ['firstname' => 'Lea', 'lastname' => 'Novak']);

        $main = $review['persons'][0];
        self::assertSame('new', $main['defaultTarget']);
        self::assertTrue($main['differsFromBooker']);
        self::assertTrue($this->option($main, 'booker')['overwrites']);
        self::assertFalse($review['simple'], 'The booker in the room may not travel: the hotelier decides.');
        self::assertSame([['id' => 1, 'name' => 'Anna Müller', 'isBooker' => true]], $review['roomGuests']);
    }

    public function testUnambiguousSuggestionsThatFitAreSimple(): void
    {
        $booker = $this->customer(1, 'Anna', 'Müller');
        $reservation = $this->reservation($booker, 2);
        $reservation->addCustomer($booker);

        $review = $this->review($reservation, ['firstname' => 'Anna', 'lastname' => 'Müller'], [['firstname' => 'Max', 'lastname' => 'Müller']]);

        self::assertSame('new', $review['persons'][1]['defaultTarget']);
        self::assertTrue($review['persons'][1]['livesWithMain']);
        self::assertTrue($review['simple']);
    }

    public function testMorePeopleThanBookedNeedALook(): void
    {
        $booker = $this->customer(1, 'Anna', 'Müller');
        $reservation = $this->reservation($booker, 1);

        $review = $this->review($reservation, ['firstname' => 'Anna', 'lastname' => 'Müller'], [['firstname' => 'Max', 'lastname' => 'Müller']]);

        self::assertFalse($review['simple']);
    }

    /**
     * @param array<string, mixed>       $mainGuest
     * @param list<array<string, mixed>> $companions
     *
     * @return array<string, mixed>
     */
    private function review(Reservation $reservation, array $mainGuest, array $companions = []): array
    {
        $checkIn = new GuestCheckIn($reservation, 'selector');
        $checkIn->recordSubmission(['v' => 1, 'mainGuest' => $mainGuest, 'companions' => $companions], new \DateTimeImmutable('2026-09-20'));

        $repository = $this->createStub(GuestCheckInRepository::class);
        $repository->method('findOneByReservation')->willReturn($checkIn);
        $config = $this->createStub(GuestCheckInConfigService::class);
        $config->method('isEnabled')->willReturn(true);
        $publicUrl = $this->createStub(PublicUrlService::class);
        $publicUrl->method('getBaseUrl')->willReturn('https://example.com');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $service = new GuestCheckInReviewService(
            $repository,
            $config,
            new GuestCheckInPolicy(new MockClock('2026-09-20 12:00', date_default_timezone_get())),
            $publicUrl,
            $translator,
            $this->createStub(GuestCheckInExtrasService::class),
            new GuestCheckInExistingGuestMatcher($this->createStub(EntityManagerInterface::class)),
            $this->createStub(GuestCheckInInvitationService::class),
            'de',
        );

        $tab = $service->buildTab($reservation);
        self::assertIsArray($tab);
        self::assertIsArray($tab['review']);

        return $tab['review'];
    }

    /**
     * @param array<string, mixed> $person
     *
     * @return array<string, mixed>
     */
    private function option(array $person, string $value): array
    {
        foreach ($person['options'] as $option) {
            if ($option['value'] === $value) {
                return $option;
            }
        }

        self::fail('Option '.$value.' missing.');
    }

    private function reservation(Customer $booker, int $persons): Reservation
    {
        $reservation = new Reservation();
        (new \ReflectionProperty(Reservation::class, 'id'))->setValue($reservation, 7);
        $reservation->setStartDate(new \DateTime('2026-10-10'));
        $reservation->setEndDate(new \DateTime('2026-10-12'));
        $reservation->setAppartment(new Appartment());
        $reservation->setReservationStatus(new ReservationStatus());
        $reservation->setPersons($persons);
        $reservation->setBooker($booker);

        return $reservation;
    }

    private function customer(int $id, string $firstname, string $lastname): Customer
    {
        $customer = new Customer();
        (new \ReflectionProperty(Customer::class, 'id'))->setValue($customer, $id);
        $customer->setSalutation('');
        $customer->setFirstname($firstname);
        $customer->setLastname($lastname);

        return $customer;
    }
}
