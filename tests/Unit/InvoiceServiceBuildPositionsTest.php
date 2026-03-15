<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use App\Entity\AppSettings;
use App\Entity\Price;
use App\Entity\Reservation;
use App\Entity\RoomCategory;
use App\Service\AppSettingsService;
use App\Service\InvoiceService;
use App\Service\PriceService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Contracts\Translation\TranslatorInterface;

final class InvoiceServiceBuildPositionsTest extends TestCase
{
    // ─── buildAppartmentPositions ───────────────────────────────────────

    public function testBuildAppartmentPositionsOvernightStay(): void
    {
        $price = $this->createApartmentPrice(1, 50.0);
        $reservation = $this->createReservation(1, 2, '2026-03-25', '2026-03-27');

        $priceService = $this->createStub(PriceService::class);
        $priceService->method('getPricesForReservationDays')
            ->willReturn([0 => [$price], 1 => [$price], 2 => [$price]]);

        $service = $this->createService($priceService);
        $positions = $service->buildAppartmentPositions($reservation);

        self::assertCount(1, $positions);
        self::assertSame('2026-03-25', $positions[0]->getStartDate()->format('Y-m-d'));
        self::assertSame('2026-03-27', $positions[0]->getEndDate()->format('Y-m-d'));
        // 2 nights × 2 persons = 4
        self::assertSame(4, $positions[0]->getAmount());
        self::assertSame(200.0, $positions[0]->getTotalPriceRaw());
    }

    public function testBuildAppartmentPositionsSingleNight(): void
    {
        $price = $this->createApartmentPrice(1, 80.0);
        $reservation = $this->createReservation(1, 1, '2026-03-25', '2026-03-26');

        $priceService = $this->createStub(PriceService::class);
        $priceService->method('getPricesForReservationDays')
            ->willReturn([0 => [$price], 1 => [$price]]);

        $service = $this->createService($priceService);
        $positions = $service->buildAppartmentPositions($reservation);

        self::assertCount(1, $positions);
        self::assertSame('2026-03-25', $positions[0]->getStartDate()->format('Y-m-d'));
        self::assertSame('2026-03-26', $positions[0]->getEndDate()->format('Y-m-d'));
        // 1 night × 1 person = 1
        self::assertSame(1, $positions[0]->getAmount());
        self::assertSame(80.0, $positions[0]->getTotalPriceRaw());
    }

    public function testBuildAppartmentPositionsSameDay(): void
    {
        $price = $this->createApartmentPrice(1, 60.0);
        $reservation = $this->createReservation(1, 2, '2026-03-25', '2026-03-25');

        $priceService = $this->createStub(PriceService::class);
        $priceService->method('getPricesForReservationDays')
            ->willReturn([0 => [$price]]);

        $service = $this->createService($priceService);
        $positions = $service->buildAppartmentPositions($reservation);

        self::assertCount(1, $positions);
        self::assertSame('2026-03-25', $positions[0]->getStartDate()->format('Y-m-d'));
        self::assertSame('2026-03-25', $positions[0]->getEndDate()->format('Y-m-d'));
        // same-day: 1 unit × 2 persons = 2
        self::assertSame(2, $positions[0]->getAmount());
        self::assertSame(120.0, $positions[0]->getTotalPriceRaw());
    }

    public function testBuildAppartmentPositionsSameDayPerRoom(): void
    {
        $price = $this->createApartmentPrice(1, 100.0, true);
        $reservation = $this->createReservation(1, 3, '2026-03-25', '2026-03-25');

        $priceService = $this->createStub(PriceService::class);
        $priceService->method('getPricesForReservationDays')
            ->willReturn([0 => [$price]]);

        $service = $this->createService($priceService);
        $positions = $service->buildAppartmentPositions($reservation);

        self::assertCount(1, $positions);
        // per room: 1 unit × 1 = 1
        self::assertSame(1, $positions[0]->getAmount());
        self::assertSame(100.0, $positions[0]->getTotalPriceRaw());
    }

    public function testBuildAppartmentPositionsSameDayFlatPrice(): void
    {
        $price = $this->createApartmentPrice(1, 150.0, false, true);
        $reservation = $this->createReservation(1, 2, '2026-03-25', '2026-03-25');

        $priceService = $this->createStub(PriceService::class);
        $priceService->method('getPricesForReservationDays')
            ->willReturn([0 => [$price]]);

        $service = $this->createService($priceService);
        $positions = $service->buildAppartmentPositions($reservation);

        self::assertCount(1, $positions);
        // flat price: always amount 1
        self::assertSame(1, $positions[0]->getAmount());
        self::assertSame(150.0, $positions[0]->getTotalPriceRaw());
    }

    public function testBuildAppartmentPositionsMultiplePricePeriods(): void
    {
        $priceA = $this->createApartmentPrice(1, 50.0);
        $priceB = $this->createApartmentPrice(2, 70.0);
        $reservation = $this->createReservation(1, 1, '2026-03-25', '2026-03-28');

        // 3 nights: first 2 at priceA, last at priceB
        $priceService = $this->createStub(PriceService::class);
        $priceService->method('getPricesForReservationDays')
            ->willReturn([0 => [$priceA], 1 => [$priceA], 2 => [$priceB], 3 => [$priceB]]);

        $service = $this->createService($priceService);
        $positions = $service->buildAppartmentPositions($reservation);

        self::assertCount(2, $positions);
        // First period: 25.03 - 27.03 (2 nights at 50)
        self::assertSame('2026-03-25', $positions[0]->getStartDate()->format('Y-m-d'));
        self::assertSame('2026-03-27', $positions[0]->getEndDate()->format('Y-m-d'));
        self::assertSame(50.0, (float) $positions[0]->getPrice());
        // Second period: 27.03 - 28.03 (1 night at 70)
        self::assertSame('2026-03-27', $positions[1]->getStartDate()->format('Y-m-d'));
        self::assertSame('2026-03-28', $positions[1]->getEndDate()->format('Y-m-d'));
        self::assertSame(70.0, (float) $positions[1]->getPrice());
    }

    // ─── prefillMiscPositions (same-day) ───────────────────────────────

    public function testPrefillMiscPositionsSameDayPerPerson(): void
    {
        $price = $this->createMiscPrice(1001, false);
        $reservation = $this->createReservation(2001, 2, '2026-03-25', '2026-03-25');
        $requestStack = $this->createRequestStack();

        $priceService = $this->createStub(PriceService::class);
        $priceService->method('getPricesForReservationDays')
            ->willReturn([0 => null, 1 => [$price]]);

        $service = $this->createService($priceService);
        $service->prefillMiscPositionsWithReservations([$reservation], $requestStack);

        $positions = $requestStack->getSession()->get('invoicePositionsMiscellaneous');
        self::assertCount(1, $positions);
        // same-day, 2 persons, per-person → amount = 2
        self::assertSame(2, $positions[0]->getAmount());
    }

    public function testPrefillMiscPositionsSameDayPerRoom(): void
    {
        $price = $this->createMiscPrice(1002, true);
        $reservation = $this->createReservation(2002, 3, '2026-03-25', '2026-03-25');
        $requestStack = $this->createRequestStack();

        $priceService = $this->createStub(PriceService::class);
        $priceService->method('getPricesForReservationDays')
            ->willReturn([0 => null, 1 => [$price]]);

        $service = $this->createService($priceService);
        $service->prefillMiscPositionsWithReservations([$reservation], $requestStack);

        $positions = $requestStack->getSession()->get('invoicePositionsMiscellaneous');
        self::assertCount(1, $positions);
        // same-day, per-room → amount = 1
        self::assertSame(1, $positions[0]->getAmount());
    }

    public function testPrefillMiscPositionsOvernightStay(): void
    {
        $price = $this->createMiscPrice(1003, false);
        $reservation = $this->createReservation(2003, 2, '2026-03-25', '2026-03-27');
        $requestStack = $this->createRequestStack();

        $priceService = $this->createStub(PriceService::class);
        $priceService->method('getPricesForReservationDays')
            ->willReturn([0 => null, 1 => [$price], 2 => [$price]]);

        $service = $this->createService($priceService);
        $service->prefillMiscPositionsWithReservations([$reservation], $requestStack);

        $positions = $requestStack->getSession()->get('invoicePositionsMiscellaneous');
        self::assertCount(1, $positions);
        // 2 nights × 2 persons = 4
        self::assertSame(4, $positions[0]->getAmount());
    }

    // ─── InvoiceAppartment::getAmount (entity-level) ───────────────────

    public function testInvoiceAppartmentAmountOvernightPerPerson(): void
    {
        $position = $this->createInvoiceAppartment('2026-03-25', '2026-03-27', 2, false, false);
        // 2 nights × 2 persons = 4
        self::assertSame(4, $position->getAmount());
    }

    public function testInvoiceAppartmentAmountOvernightPerRoom(): void
    {
        $position = $this->createInvoiceAppartment('2026-03-25', '2026-03-27', 2, false, true);
        // 2 nights × 1 (per room) = 2
        self::assertSame(2, $position->getAmount());
    }

    public function testInvoiceAppartmentAmountSameDayPerPerson(): void
    {
        $position = $this->createInvoiceAppartment('2026-03-25', '2026-03-25', 2, false, false);
        // same-day: max(1, 0) = 1, × 2 persons = 2
        self::assertSame(2, $position->getAmount());
    }

    public function testInvoiceAppartmentAmountSameDayPerRoom(): void
    {
        $position = $this->createInvoiceAppartment('2026-03-25', '2026-03-25', 2, false, true);
        // same-day: max(1, 0) = 1, × 1 (per room) = 1
        self::assertSame(1, $position->getAmount());
    }

    public function testInvoiceAppartmentAmountFlatPrice(): void
    {
        $position = $this->createInvoiceAppartment('2026-03-25', '2026-03-25', 2, true, false);
        // flat price always returns 1
        self::assertSame(1, $position->getAmount());
    }

    // ─── Reservation::getAmount ────────────────────────────────────────

    public function testReservationAmountOvernight(): void
    {
        $reservation = new Reservation();
        $reservation->setStartDate(new \DateTime('2026-03-25'));
        $reservation->setEndDate(new \DateTime('2026-03-27'));
        self::assertSame(2, $reservation->getAmount());
    }

    public function testReservationAmountSameDay(): void
    {
        $reservation = new Reservation();
        $reservation->setStartDate(new \DateTime('2026-03-25'));
        $reservation->setEndDate(new \DateTime('2026-03-25'));
        self::assertSame(1, $reservation->getAmount());
    }

    public function testReservationAmountSingleNight(): void
    {
        $reservation = new Reservation();
        $reservation->setStartDate(new \DateTime('2026-03-25'));
        $reservation->setEndDate(new \DateTime('2026-03-26'));
        self::assertSame(1, $reservation->getAmount());
    }

    // ─── Helpers ───────────────────────────────────────────────────────

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

    private function createApartmentPrice(int $id, float $priceValue, bool $isPerRoom = false, bool $isFlatPrice = false): Price
    {
        $price = new Price();
        $price->setId($id);
        $price->setDescription('Apartment price');
        $price->setPrice($priceValue);
        $price->setVat(0);
        $price->setIncludesVat(false);
        $price->setIsFlatPrice($isFlatPrice);
        $price->setIsPerRoom($isPerRoom);
        $price->setActive(true);

        return $price;
    }

    private function createMiscPrice(int $id, bool $isPerRoom): Price
    {
        $price = new Price();
        $price->setId($id);
        $price->setDescription('Misc price');
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
        $roomCategory = new RoomCategory();
        $roomCategory->setName('Standard');

        $appartment = new Appartment();
        $appartment->setNumber('101');
        $appartment->setDescription('Test Room');
        $appartment->setBedsMax(2);
        $appartment->setRoomCategory($roomCategory);

        $reservation = new Reservation();
        $reservation->setId($id);
        $reservation->setPersons($persons);
        $reservation->setStartDate(new \DateTime($startDate));
        $reservation->setEndDate(new \DateTime($endDate));
        $reservation->setAppartment($appartment);

        return $reservation;
    }

    private function createInvoiceAppartment(
        string $startDate,
        string $endDate,
        int $persons,
        bool $isFlatPrice,
        bool $isPerRoom,
    ): \App\Entity\InvoiceAppartment {
        $position = new \App\Entity\InvoiceAppartment();
        $position->setStartDate(new \DateTime($startDate));
        $position->setEndDate(new \DateTime($endDate));
        $position->setPersons($persons);
        $position->setPrice(50);
        $position->setVat(0);
        $position->setIncludesVat(false);
        $position->setIsFlatPrice($isFlatPrice);
        $position->setIsPerRoom($isPerRoom);

        return $position;
    }
}
