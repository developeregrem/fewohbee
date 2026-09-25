<?php

declare(strict_types=1);

namespace App\Tests\Unit\GuestCheckIn;

use App\Entity\Customer;
use App\Entity\CustomerAddresses;
use App\Entity\Enum\IDCardType;
use App\Entity\GuestCheckIn;
use App\Entity\Reservation;
use App\Service\GuestCheckIn\GuestCheckInApplyService;
use App\Service\GuestCheckIn\GuestCheckInFormDataFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class GuestCheckInFormDataFactoryTest extends TestCase
{
    public function testReturningGuestFindsTheFormFilledFromTheRecords(): void
    {
        $reservation = $this->reservation();
        $data = $this->factory()->create(new GuestCheckIn($reservation, 'selector'), 2, ['Ms', 'Mr']);

        $guest = $data->mainGuest;
        self::assertSame('Ms', $guest->salutation, 'Stored "Frau" maps back to the configured entry.');
        self::assertSame('Anna', $guest->firstname);
        self::assertSame('1980-05-01', $guest->birthday?->format('Y-m-d'));
        self::assertSame('AT', $guest->nationality);
        self::assertSame(IDCardType::PASSPORT, $guest->idType);
        self::assertNull($guest->idNumber, 'ID numbers never go into the page.');
        self::assertSame('Ring 1', $guest->street);
        self::assertSame('anna@example.com', $guest->email);
        self::assertNull($guest->phone, 'A stored value the form would reject is left out.');
        self::assertSame('18:00', $data->arrivalTime);

        self::assertCount(2, $data->companions);
        self::assertSame('Max', $data->companions[0]->firstname);
        self::assertSame('Graz', $data->companions[0]->city, 'A different address on file is the companion\'s own.');
        self::assertTrue($data->companions[1]->isEmpty());
    }

    public function testTheGuestsOwnSubmissionWins(): void
    {
        $checkIn = new GuestCheckIn($this->reservation(), 'selector');
        $checkIn->recordSubmission(['v' => 1, 'arrivalTime' => '20:00', 'mainGuest' => ['firstname' => 'Annemarie', 'idNumber' => 'P7654321']], new \DateTimeImmutable());

        $data = $this->factory()->create($checkIn, 1, ['Ms']);

        self::assertSame('Annemarie', $data->mainGuest->firstname);
        self::assertSame('20:00', $data->arrivalTime);
        self::assertNull($data->mainGuest->idNumber);
        self::assertSame('•••321', $this->factory()->storedIdNumberHint($checkIn));
    }

    public function testIdHintFallsBackToTheRecordsAndHidesShortNumbers(): void
    {
        $reservation = $this->reservation();
        self::assertSame('•••678', $this->factory()->storedIdNumberHint(new GuestCheckIn($reservation, 'selector')));

        $reservation->getBooker()?->setIDNumber('AB12');
        self::assertSame('•••', $this->factory()->storedIdNumberHint(new GuestCheckIn($reservation, 'selector')));

        $reservation->getBooker()?->setIDNumber(null);
        self::assertNull($this->factory()->storedIdNumberHint(new GuestCheckIn($reservation, 'selector')));
    }

    public function testAnyAnnouncedArrivalTimeIsPrefilled(): void
    {
        $reservation = $this->reservation();
        $reservation->setArrivalTime(new \DateTime('18:15'));

        self::assertSame('18:15', $this->factory()->create(new GuestCheckIn($reservation, 'selector'), 0, [])->arrivalTime);
    }

    private function factory(): GuestCheckInFormDataFactory
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => ['Ms' => 'Frau', 'Mr' => 'Herr'][$id] ?? $id);

        return new GuestCheckInFormDataFactory($translator, 'de');
    }

    private function reservation(): Reservation
    {
        $booker = new Customer();
        $booker->setSalutation('Frau');
        $booker->setFirstname('Anna');
        $booker->setLastname('Müller');
        $booker->setBirthday(new \DateTime('1980-05-01'));
        $booker->setNationality('AT');
        $booker->setIdType(IDCardType::PASSPORT);
        $booker->setIDNumber('P12345678');
        $address = (new CustomerAddresses())->setType(GuestCheckInApplyService::ADDRESS_TYPE_PRIVATE)
            ->setAddress('Ring 1')->setZip('1010')->setCity('Wien')->setCountry('AT')
            ->setEmail('anna@example.com')->setPhone('call me maybe');
        $booker->addCustomerAddress($address);

        $companion = new Customer();
        $companion->setFirstname('Max');
        $companion->setLastname('Müller');
        $companion->addCustomerAddress((new CustomerAddresses())->setType(GuestCheckInApplyService::ADDRESS_TYPE_PRIVATE)
            ->setAddress('Hauptplatz 2')->setZip('8010')->setCity('Graz')->setCountry('AT'));

        $reservation = new Reservation();
        $reservation->setBooker($booker);
        $reservation->addCustomer($booker);
        $reservation->addCustomer($companion);
        $reservation->setArrivalTime(new \DateTime('18:00'));

        return $reservation;
    }
}
