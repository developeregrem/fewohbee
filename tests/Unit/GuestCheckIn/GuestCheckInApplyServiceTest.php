<?php

declare(strict_types=1);

namespace App\Tests\Unit\GuestCheckIn;

use App\Dto\GuestCheckIn\GuestCheckInApplyRequest;
use App\Entity\Customer;
use App\Entity\AppSettings;
use App\Entity\CustomerAddresses;
use App\Entity\Enum\GuestCheckInStatus;
use App\Entity\GuestCheckIn;
use App\Entity\Reservation;
use App\Event\GuestCheckInConfirmedEvent;
use App\Repository\AppSettingsRepository;
use App\Service\GuestCheckIn\GuestCheckInApplyService;
use App\Service\GuestCheckIn\GuestCheckInExtrasService;
use App\Service\GuestCheckIn\GuestCheckInExistingGuestMatcher;
use App\Service\GuestCheckIn\GuestCheckInNoGuestSlotException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\Translation\TranslatorInterface;

final class GuestCheckInApplyServiceTest extends TestCase
{
    public function testBookerIsCompletedWithoutLosingExistingValues(): void
    {
        $booker = $this->customer(1, 'Anna', 'Müller');
        $booker->setIDNumber('OLD-123');
        $reservation = $this->reservation($booker, 3);
        $checkIn = $this->submitted($reservation, ['firstname' => 'Anna', 'lastname' => 'Müller', 'salutation' => 'Ms', 'birthday' => '1980-05-01', 'nationality' => 'AT', 'idNumber' => null]);

        $this->service()->apply($checkIn, new GuestCheckInApplyRequest('booker', false, []));

        self::assertSame('1980-05-01', $booker->getBirthday()?->format('Y-m-d'));
        self::assertSame('AT', $booker->getNationality());
        self::assertSame('OLD-123', $booker->getIDNumber(), 'Nothing entered, nothing overwritten.');
        self::assertSame('Frau', $booker->getSalutation(), 'Stored in the installation language.');
        self::assertTrue($reservation->getCustomers()->contains($booker));
        self::assertSame(GuestCheckInStatus::APPLIED, $checkIn->getStatus());
        self::assertFalse($checkIn->hasPayload());
    }

    public function testSharedAddressIsNotChangedForTheOtherCustomer(): void
    {
        $booker = $this->customer(1, 'Anna', 'Müller');
        $partner = $this->customer(2, 'Max', 'Müller');
        $shared = (new CustomerAddresses())->setType(GuestCheckInApplyService::ADDRESS_TYPE_PRIVATE)->setCity('Berlin');
        $shared->addCustomer($booker);
        $shared->addCustomer($partner);
        $booker->addCustomerAddress($shared);
        $partner->addCustomerAddress($shared);
        $checkIn = $this->submitted($this->reservation($booker, 2), ['firstname' => 'Anna', 'lastname' => 'Müller', 'address' => ['city' => 'Wien', 'country' => 'AT']]);

        $this->service()->apply($checkIn, new GuestCheckInApplyRequest('booker', false, []));

        self::assertSame('Berlin', $shared->getCity());
        self::assertCount(2, $booker->getCustomerAddresses());
        self::assertSame('Wien', $booker->getCustomerAddresses()[1]->getCity());
    }

    public function testNewCompanionGetsACopyOfTheMainAddress(): void
    {
        $booker = $this->customer(1, 'Anna', 'Müller');
        $reservation = $this->reservation($booker, 2);
        $checkIn = $this->submitted(
            $reservation,
            ['firstname' => 'Anna', 'lastname' => 'Müller', 'address' => ['street' => 'Ring 1', 'city' => 'Wien'], 'email' => 'anna@example.com'],
            [['salutation' => 'Ms', 'firstname' => 'Max', 'lastname' => 'Müller', 'birthday' => '2015-03-03']],
        );

        $this->service()->apply($checkIn, new GuestCheckInApplyRequest('booker', false, ['new']));

        $companion = $reservation->getCustomers()->filter(static fn (Customer $c): bool => 'Max' === $c->getFirstname())->first();
        self::assertInstanceOf(Customer::class, $companion);
        self::assertSame('Frau', $companion->getSalutation());
        $address = $companion->getCustomerAddresses()->first();
        self::assertInstanceOf(CustomerAddresses::class, $address);
        self::assertSame('Wien', $address->getCity());
        self::assertNull($address->getEmail(), 'Contact details stay with the main guest.');
        self::assertNotSame($booker->getCustomerAddresses()->first(), $address);
    }

    public function testCompanionWithOwnAddressGetsThatOne(): void
    {
        $booker = $this->customer(1, 'Anna', 'Müller');
        $reservation = $this->reservation($booker, 2);
        $checkIn = $this->submitted(
            $reservation,
            ['firstname' => 'Anna', 'lastname' => 'Müller', 'address' => ['street' => 'Ring 1', 'city' => 'Wien']],
            [['firstname' => 'Tom', 'lastname' => 'Novak', 'address' => ['street' => 'Hauptplatz 2', 'zip' => '8010', 'city' => 'Graz', 'country' => 'AT']]],
        );

        $this->service()->apply($checkIn, new GuestCheckInApplyRequest('booker', false, ['new']));

        $companion = $reservation->getCustomers()->filter(static fn (Customer $c): bool => 'Tom' === $c->getFirstname())->first();
        self::assertInstanceOf(Customer::class, $companion);
        self::assertSame('Graz', $companion->getCustomerAddresses()->first()?->getCity());
    }

    public function testExistingGuestKeepsTheirAddressWhenLivingWithTheMainGuest(): void
    {
        $booker = $this->customer(1, 'Anna', 'Müller');
        $partner = $this->customer(2, 'Max', 'Müller');
        $partner->addCustomerAddress((new CustomerAddresses())->setType(GuestCheckInApplyService::ADDRESS_TYPE_PRIVATE)->setCity('Linz'));
        $reservation = $this->reservation($booker, 2);
        $reservation->addCustomer($partner);
        $checkIn = $this->submitted($reservation, ['firstname' => 'Anna', 'lastname' => 'Müller', 'address' => ['city' => 'Wien']], [['firstname' => 'Max', 'lastname' => 'Müller']]);

        $this->service()->apply($checkIn, new GuestCheckInApplyRequest('booker', false, ['customer:2']));

        self::assertCount(1, $partner->getCustomerAddresses());
        self::assertSame('Linz', $partner->getCustomerAddresses()->first()?->getCity());
    }

    public function testGuestsBeyondTheOccupancyAreNotAdded(): void
    {
        $booker = $this->customer(1, 'Anna', 'Müller');
        $reservation = $this->reservation($booker, 1);
        $checkIn = $this->submitted($reservation, ['firstname' => 'Anna', 'lastname' => 'Müller'], [['firstname' => 'Max', 'lastname' => 'Müller']]);

        $warnings = $this->service()->apply($checkIn, new GuestCheckInApplyRequest('booker', false, ['new']));

        self::assertSame(['guest_checkin.apply.warning_guest_count'], $warnings);
        self::assertCount(1, $reservation->getCustomers());
    }

    public function testMainGuestBecomesBookerOfAnImportedReservation(): void
    {
        $reservation = $this->reservation(null, 2);
        $checkIn = $this->submitted($reservation, ['firstname' => 'Lea', 'lastname' => 'Novak']);

        $this->service()->apply($checkIn, new GuestCheckInApplyRequest('new', false, []));

        self::assertSame('Novak', $reservation->getBooker()?->getLastname());
    }

    public function testCustomersOutsideTheReservationCannotBeTargeted(): void
    {
        $checkIn = $this->submitted($this->reservation($this->customer(1, 'Anna', 'Müller'), 2), ['firstname' => 'Anna', 'lastname' => 'Müller']);

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->apply($checkIn, new GuestCheckInApplyRequest('customer:999', false, []));
    }

    public function testSameCustomerCannotRepresentTwoTravellers(): void
    {
        $booker = $this->customer(1, 'Anna', 'Müller');
        $guest = $this->customer(2, 'Original', 'Guest');
        $reservation = $this->reservation($booker, 3);
        $reservation->addCustomer($guest);
        $checkIn = $this->submitted($reservation, ['firstname' => 'Anna', 'lastname' => 'Müller'], [
            ['firstname' => 'First', 'lastname' => 'Guest'],
            ['firstname' => 'Second', 'lastname' => 'Guest'],
        ]);

        try {
            $this->service()->apply($checkIn, new GuestCheckInApplyRequest('booker', false, ['customer:2', 'customer:2']));
            self::fail('A customer cannot represent two travellers.');
        } catch (\InvalidArgumentException) {
            self::assertSame('Original', $guest->getFirstname());
            self::assertTrue($checkIn->hasPayload());
        }
    }

    public function testOutdatedReviewDoesNotChangeGuestRecords(): void
    {
        $booker = $this->customer(1, 'Anna', 'Müller');
        $checkIn = $this->submitted($this->reservation($booker, 2), ['firstname' => 'Changed', 'lastname' => 'Müller']);

        try {
            $this->service()->apply($checkIn, new GuestCheckInApplyRequest('booker', false, [], 'old-version'));
            self::fail('An outdated review must be refused.');
        } catch (\InvalidArgumentException) {
            self::assertSame('Anna', $booker->getFirstname());
            self::assertTrue($checkIn->hasPayload());
        }
    }

    public function testChoosingTheBookerForADifferentPersonOverwritesTheBooker(): void
    {
        // The review never preselects a record whose name differs; picking it is the decision.
        $booker = $this->customer(1, 'Anna', 'Müller');
        $checkIn = $this->submitted($this->reservation($booker, 2), ['firstname' => 'Lea', 'lastname' => 'Novak']);

        $this->service()->apply($checkIn, new GuestCheckInApplyRequest('customer:1', false, []));

        self::assertSame('Lea', $booker->getFirstname());
        self::assertSame(GuestCheckInStatus::APPLIED, $checkIn->getStatus());
    }

    public function testGuestMarkedAsNotTravellingMakesRoomForTheArrivingGuest(): void
    {
        $booker = $this->customer(1, 'Anna', 'Müller');
        $reservation = $this->reservation($booker, 1);
        $reservation->addCustomer($booker);
        $checkIn = $this->submitted($reservation, ['firstname' => 'Lea', 'lastname' => 'Novak']);

        try {
            $this->service()->apply($checkIn, new GuestCheckInApplyRequest('new', false, []));
            self::fail('Without a free place the arriving guest cannot be added.');
        } catch (GuestCheckInNoGuestSlotException) {
            self::assertTrue($checkIn->hasPayload());
        }

        $this->service()->apply($checkIn, new GuestCheckInApplyRequest('new', false, [], removeGuestIds: [1]));

        self::assertSame(['Lea'], array_map(static fn (Customer $guest): ?string => $guest->getFirstname(), array_values($reservation->getCustomers()->toArray())));
        self::assertSame($booker, $reservation->getBooker(), 'Not travelling does not change who booked.');
    }

    public function testGuestTakenOverOrNotLinkedCannotBeRemoved(): void
    {
        $booker = $this->customer(1, 'Anna', 'Müller');
        $reservation = $this->reservation($booker, 2);
        $reservation->addCustomer($booker);
        $checkIn = $this->submitted($reservation, ['firstname' => 'Anna', 'lastname' => 'Müller']);

        foreach ([[1], [999]] as $removeGuestIds) {
            try {
                $this->service()->apply($checkIn, new GuestCheckInApplyRequest('booker', false, [], removeGuestIds: $removeGuestIds));
                self::fail('Only linked guests nobody was matched to can be removed.');
            } catch (\InvalidArgumentException) {
                self::assertTrue($reservation->getCustomers()->contains($booker));
                self::assertTrue($checkIn->hasPayload());
            }
        }
    }

    public function testConfirmingDispatchesTheConfirmedEventAfterwards(): void
    {
        $checkIn = $this->submitted($this->reservation($this->customer(1, 'Anna', 'Müller'), 2), ['firstname' => 'Anna', 'lastname' => 'Müller']);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')->with(self::callback(
            static fn (object $event): bool => $event instanceof GuestCheckInConfirmedEvent && $event->checkIn === $checkIn && !$checkIn->hasPayload(),
        ))->willReturnArgument(0);

        $this->service($dispatcher)->apply($checkIn, new GuestCheckInApplyRequest('booker', false, []));
    }

    public function testDeferredServiceRequestIsKeptInReservationRemarksWithoutBookingPrice(): void
    {
        $booker = $this->customer(1, 'Anna', 'Müller');
        $reservation = $this->reservation($booker, 2);
        $checkIn = $this->submitted($reservation, ['firstname' => 'Anna', 'lastname' => 'Müller']);
        $payload = $checkIn->getPayload();
        self::assertIsArray($payload);
        $payload['extras'] = [['id' => 123, 'description' => 'Breakfast', 'total' => '30.00']];
        $checkIn->recordSubmission($payload, new \DateTimeImmutable());

        $warnings = $this->service()->apply($checkIn, new GuestCheckInApplyRequest('booker', false, [], applyExtras: false));

        self::assertSame(['guest_checkin.apply.warning_extras_deferred'], $warnings);
        self::assertStringContainsString('Breakfast · 30,00 €', (string) $reservation->getRemark());
        self::assertCount(0, $reservation->getPrices());
        self::assertSame(GuestCheckInStatus::APPLIED, $checkIn->getStatus());
    }

    private function service(?EventDispatcherInterface $dispatcher = null): GuestCheckInApplyService
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $parameters = []): string => strtr([
            'Ms' => 'Frau',
            'guest_checkin.remark.deferred_extras' => 'Deferred: %services%',
        ][$id] ?? $id, $parameters));
        $settings = new AppSettings();
        $settingsRepository = $this->createStub(AppSettingsRepository::class);
        $settingsRepository->method('findSingleton')->willReturn($settings);

        $em = $this->createStub(EntityManagerInterface::class);

        return new GuestCheckInApplyService($em, $translator, new MockClock(), 'de', $this->createStub(GuestCheckInExtrasService::class), $settingsRepository, new GuestCheckInExistingGuestMatcher($em), $this->createStub(\App\Service\Pricing\PricePromiseService::class), $dispatcher ?? $this->createStub(EventDispatcherInterface::class));
    }

    /**
     * @param array<string, mixed>       $mainGuest
     * @param list<array<string, mixed>> $companions
     */
    private function submitted(Reservation $reservation, array $mainGuest, array $companions = []): GuestCheckIn
    {
        $checkIn = new GuestCheckIn($reservation, 'selector');
        $checkIn->recordSubmission(['v' => 1, 'mainGuest' => $mainGuest, 'companions' => $companions], new \DateTimeImmutable());

        return $checkIn;
    }

    private function reservation(?Customer $booker, int $persons): Reservation
    {
        $reservation = new Reservation();
        $reservation->setPersons($persons);
        if (null !== $booker) {
            $reservation->setBooker($booker);
        }

        return $reservation;
    }

    private function customer(int $id, string $firstname, string $lastname): Customer
    {
        $customer = new Customer();
        (new \ReflectionProperty(Customer::class, 'id'))->setValue($customer, $id);
        $customer->setSalutation('Frau');
        $customer->setFirstname($firstname);
        $customer->setLastname($lastname);

        return $customer;
    }
}
