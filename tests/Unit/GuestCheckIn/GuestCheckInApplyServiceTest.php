<?php

declare(strict_types=1);

namespace App\Tests\Unit\GuestCheckIn;

use App\Dto\GuestCheckIn\GuestCheckInApplyRequest;
use App\Entity\Customer;
use App\Entity\CustomerAddresses;
use App\Entity\Enum\GuestCheckInStatus;
use App\Entity\GuestCheckIn;
use App\Entity\Reservation;
use App\Service\GuestCheckIn\GuestCheckInApplyService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
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
            [['firstname' => 'Max', 'lastname' => 'Müller', 'birthday' => '2015-03-03']],
        );

        $this->service()->apply($checkIn, new GuestCheckInApplyRequest('booker', false, ['new']));

        $companion = $reservation->getCustomers()->filter(static fn (Customer $c): bool => 'Max' === $c->getFirstname())->first();
        self::assertInstanceOf(Customer::class, $companion);
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

    private function service(): GuestCheckInApplyService
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => ['Ms' => 'Frau'][$id] ?? $id);

        return new GuestCheckInApplyService($this->createStub(EntityManagerInterface::class), $translator, new MockClock(), 'de');
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
