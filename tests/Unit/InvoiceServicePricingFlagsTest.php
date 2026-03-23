<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\AppSettings;
use App\Entity\InvoiceAppartment;
use App\Entity\InvoicePosition;
use App\Entity\Price;
use App\Entity\Reservation;
use App\Service\AppSettingsService;
use App\Service\InvoiceService;
use App\Service\PriceService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Contracts\Translation\TranslatorInterface;

final class InvoiceServicePricingFlagsTest extends TestCase
{
    public function testPrefillMiscPositionsCalculatesPerPersonAmount(): void
    {
        $price = $this->createPrice(1001, false);
        $reservation = $this->createReservation(2001, 2, '2026-01-01', '2026-01-03');
        $requestStack = $this->createRequestStack();

        $priceService = $this->createMock(PriceService::class);
        $priceService
            ->expects(self::once())
            ->method('getPricesForReservationDays')
            ->with($reservation, 1, null)
            ->willReturn([0 => null, 1 => [$price], 2 => [$price]]);

        $service = $this->createService($priceService);
        $service->prefillMiscPositionsWithReservations([$reservation], $requestStack);

        $positions = $requestStack->getSession()->get('invoicePositionsMiscellaneous');
        self::assertCount(1, $positions);
        self::assertSame(4, $positions[0]->getAmount());

        $vats = [];
        $brutto = 0.0;
        $netto = 0.0;
        $apartmentTotal = 0.0;
        $miscTotal = 0.0;
        $service->calculateSums(new ArrayCollection(), $positions, $vats, $brutto, $netto, $apartmentTotal, $miscTotal);

        self::assertSame(40.0, $miscTotal);
    }

    public function testPrefillMiscPositionsCalculatesPerRoomAmount(): void
    {
        $price = $this->createPrice(1002, true);
        $reservation = $this->createReservation(2002, 2, '2026-01-01', '2026-01-03');
        $requestStack = $this->createRequestStack();

        $priceService = $this->createMock(PriceService::class);
        $priceService
            ->expects(self::once())
            ->method('getPricesForReservationDays')
            ->with($reservation, 1, null)
            ->willReturn([0 => null, 1 => [$price], 2 => [$price]]);

        $service = $this->createService($priceService);
        $service->prefillMiscPositionsWithReservations([$reservation], $requestStack);

        $positions = $requestStack->getSession()->get('invoicePositionsMiscellaneous');
        self::assertCount(1, $positions);
        self::assertSame(2, $positions[0]->getAmount());

        $vats = [];
        $brutto = 0.0;
        $netto = 0.0;
        $apartmentTotal = 0.0;
        $miscTotal = 0.0;
        $service->calculateSums(new ArrayCollection(), $positions, $vats, $brutto, $netto, $apartmentTotal, $miscTotal);

        self::assertSame(20.0, $miscTotal);
    }

    public function testCalculateSumsUsesApartmentPerRoomFlag(): void
    {
        $service = $this->createService($this->createStub(PriceService::class));

        $perPerson = new InvoiceAppartment();
        $perPerson->setStartDate(new \DateTime('2026-01-01'));
        $perPerson->setEndDate(new \DateTime('2026-01-03'));
        $perPerson->setPersons(2);
        $perPerson->setPrice(50);
        $perPerson->setVat(0);
        $perPerson->setIncludesVat(false);
        $perPerson->setIsFlatPrice(false);
        $perPerson->setIsPerRoom(false);

        $perRoom = new InvoiceAppartment();
        $perRoom->setStartDate(new \DateTime('2026-01-01'));
        $perRoom->setEndDate(new \DateTime('2026-01-03'));
        $perRoom->setPersons(2);
        $perRoom->setPrice(50);
        $perRoom->setVat(0);
        $perRoom->setIncludesVat(false);
        $perRoom->setIsFlatPrice(false);
        $perRoom->setIsPerRoom(true);

        $vats = [];
        $brutto = 0.0;
        $netto = 0.0;
        $apartmentTotal = 0.0;
        $miscTotal = 0.0;

        $service->calculateSums(new ArrayCollection([$perPerson]), new ArrayCollection(), $vats, $brutto, $netto, $apartmentTotal, $miscTotal);
        self::assertSame(200.0, $apartmentTotal);

        $vats = [];
        $brutto = 0.0;
        $netto = 0.0;
        $apartmentTotal = 0.0;
        $miscTotal = 0.0;

        $service->calculateSums(new ArrayCollection([$perRoom]), new ArrayCollection(), $vats, $brutto, $netto, $apartmentTotal, $miscTotal);
        self::assertSame(100.0, $apartmentTotal);
    }

    public function testCalculateSumsIncludesVatTrueExtractsVatFromGross(): void
    {
        $service = $this->createService($this->createStub(PriceService::class));

        // 119.00€ brutto/night × 3 nights, 7% VAT (typical hotel room scenario)
        $apartment = new InvoiceAppartment();
        $apartment->setStartDate(new \DateTime('2026-03-28'));
        $apartment->setEndDate(new \DateTime('2026-03-31'));
        $apartment->setPersons(1);
        $apartment->setBeds(1);
        $apartment->setNumber('1');
        $apartment->setDescription('EZ');
        $apartment->setPrice(119);
        $apartment->setVat(7);
        $apartment->setIncludesVat(true);
        $apartment->setIsFlatPrice(false);
        $apartment->setIsPerRoom(true);

        // 10.00€ brutto × 1, 19% VAT (e.g. breakfast)
        $misc = new InvoicePosition();
        $misc->setDescription('Frühstück');
        $misc->setAmount(1);
        $misc->setPrice(10);
        $misc->setVat(19);
        $misc->setIncludesVat(true);
        $misc->setIsFlatPrice(false);
        $misc->setIsPerRoom(false);

        $vats = [];
        $brutto = 0.0;
        $netto = 0.0;
        $apartmentTotal = 0.0;
        $miscTotal = 0.0;

        $service->calculateSums(
            new ArrayCollection([$apartment]),
            new ArrayCollection([$misc]),
            $vats,
            $brutto,
            $netto,
            $apartmentTotal,
            $miscTotal,
        );

        // Apartment: 3 × 119.00 = 357.00 brutto
        self::assertSame(357.0, $apartmentTotal);
        // Misc: 1 × 10.00 = 10.00 brutto
        self::assertSame(10.0, $miscTotal);

        // Total brutto = 357.00 + 10.00 = 367.00
        self::assertEqualsWithDelta(367.0, $brutto, 0.01);

        // VAT 7%: 357.00 × 7 / 107 = 23.3551... → rounded 23.36
        self::assertArrayHasKey(7.0, $vats);
        self::assertEqualsWithDelta(357.0, $vats[7.0]['brutto'], 0.01);
        self::assertEqualsWithDelta(23.36, round($vats[7.0]['netto'], 2), 0.01);
        self::assertEqualsWithDelta(333.64, round($vats[7.0]['netSum'], 2), 0.01);

        // VAT 19%: 10.00 × 19 / 119 = 1.5966... → rounded 1.60
        self::assertArrayHasKey(19.0, $vats);
        self::assertEqualsWithDelta(10.0, $vats[19.0]['brutto'], 0.01);
        self::assertEqualsWithDelta(1.60, round($vats[19.0]['netto'], 2), 0.01);
        self::assertEqualsWithDelta(8.40, round($vats[19.0]['netSum'], 2), 0.01);
    }

    public function testCalculateSumsIncludesVatFalseAddsVatToNet(): void
    {
        $service = $this->createService($this->createStub(PriceService::class));

        // 100.00€ netto × 2, 19% VAT
        $misc = new InvoicePosition();
        $misc->setDescription('Service');
        $misc->setAmount(2);
        $misc->setPrice(100);
        $misc->setVat(19);
        $misc->setIncludesVat(false);
        $misc->setIsFlatPrice(false);
        $misc->setIsPerRoom(false);

        $vats = [];
        $brutto = 0.0;
        $netto = 0.0;
        $apartmentTotal = 0.0;
        $miscTotal = 0.0;

        $service->calculateSums(
            new ArrayCollection(),
            new ArrayCollection([$misc]),
            $vats,
            $brutto,
            $netto,
            $apartmentTotal,
            $miscTotal,
        );

        self::assertSame(200.0, $miscTotal);
        // 200.00 netto + 38.00 VAT = 238.00 brutto
        self::assertEqualsWithDelta(238.0, $brutto, 0.001);
        self::assertEqualsWithDelta(38.0, $netto, 0.001);
        self::assertEqualsWithDelta(238.0, $vats[19.0]['brutto'], 0.001);
        self::assertEqualsWithDelta(38.0, $vats[19.0]['netto'], 0.001);
        self::assertEqualsWithDelta(200.0, $vats[19.0]['netSum'], 0.001);
    }

    public function testFlatPriceDisablesPerRoomFlag(): void
    {
        $price = new Price();
        $price->setIsPerRoom(true);
        $price->setIsFlatPrice(true);
        self::assertFalse($price->getIsPerRoom());

        $miscPosition = new InvoicePosition();
        $miscPosition->setIsPerRoom(true);
        $miscPosition->setIsFlatPrice(true);
        self::assertFalse($miscPosition->getIsPerRoom());

        $apartmentPosition = new InvoiceAppartment();
        $apartmentPosition->setIsPerRoom(true);
        $apartmentPosition->setIsFlatPrice(true);
        self::assertFalse($apartmentPosition->getIsPerRoom());
    }

    private function createService(PriceService $priceService): InvoiceService
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);

        $appSettings = new AppSettings();
        $appSettings->setInvoiceFilenamePattern('Invoice-<number>');
        $appSettingsService = $this->createStub(AppSettingsService::class);
        $appSettingsService->method('getSettings')->willReturn($appSettings);

        return new InvoiceService($em, $priceService, $translator, $appSettingsService);
    }

    private function createRequestStack(): RequestStack
    {
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        return $requestStack;
    }

    private function createPrice(int $id, bool $isPerRoom): Price
    {
        $price = new Price();
        $price->setId($id);
        $price->setDescription('Hund');
        $price->setPrice(10);
        $price->setVat(0);
        $price->setIncludesVat(false);
        $price->setIsFlatPrice(false);
        $price->setIsPerRoom($isPerRoom);
        $price->setActive(true);

        return $price;
    }

    private function createReservation(int $id, int $persons, string $startDate, string $endDate): Reservation
    {
        $reservation = new Reservation();
        $reservation->setId($id);
        $reservation->setPersons($persons);
        $reservation->setStartDate(new \DateTime($startDate));
        $reservation->setEndDate(new \DateTime($endDate));

        return $reservation;
    }
}
