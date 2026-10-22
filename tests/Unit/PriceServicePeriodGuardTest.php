<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Price;
use App\Entity\PricePeriod;
use App\Entity\Reservation;
use App\Exception\InvalidReservationPeriodException;
use App\Service\PriceService;
use App\Service\ReservationPeriodService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers reservation-period validation and complete, inclusive price-period boundaries.
 */
final class PriceServicePeriodGuardTest extends TestCase
{
    public function testRejectsReproducedPeriodBeforeRepositoryWork(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('getRepository');

        $reservation = new Reservation();
        $reservation->setStartDate(new \DateTime('2026-09-12'));
        $reservation->setEndDate(new \DateTime('4026-09-13'));

        $service = new PriceService($entityManager, new ReservationPeriodService());

        $this->expectException(InvalidReservationPeriodException::class);
        $this->expectExceptionMessage('reservation.period.too_long');

        $service->getPricesForReservationDays($reservation, 1);
    }

    #[DataProvider('dateMutability')]
    public function testPricePeriodIncludesBothBoundariesWithoutChangingDates(bool $immutable): void
    {
        $start = $immutable ? new \DateTimeImmutable('2026-09-12 UTC') : new \DateTime('2026-09-12 UTC');
        $end = $immutable ? new \DateTimeImmutable('2026-09-14 UTC') : new \DateTime('2026-09-14 UTC');
        $period = (new PricePeriod())->setStart($start)->setEnd($end);
        $price = $this->priceWithPeriod($period);
        $reservation = new Reservation();
        $reservation->setStartDate(new \DateTime('2026-09-11 UTC'));
        $reservation->setEndDate(new \DateTime('2026-09-15 UTC'));
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('getRepository');
        $service = new PriceService($entityManager, new ReservationPeriodService());

        $prices = $service->getPricesForReservationDays($reservation, 1, new ArrayCollection([$price]));

        self::assertSame([null, [$price], [$price], [$price], null], $prices);
        self::assertSame($start, $period->getStart());
        self::assertSame($end, $period->getEnd());
        self::assertSame('2026-09-12', $start->format('Y-m-d'));
        self::assertSame('2026-09-14', $end->format('Y-m-d'));
        self::assertSame('2026-09-11', $reservation->getStartDate()->format('Y-m-d'));
    }

    /** @return iterable<string, array{bool}> */
    public static function dateMutability(): iterable
    {
        yield 'mutable dates' => [false];
        yield 'immutable dates' => [true];
    }

    #[DataProvider('missingPriceBoundaries')]
    public function testIncompletePricePeriodStopsPricing(string $missingBoundary): void
    {
        $period = new PricePeriod();
        if ('start' !== $missingBoundary) {
            $period->setStart(new \DateTimeImmutable('2026-09-12 UTC'));
        }
        if ('end' !== $missingBoundary) {
            $period->setEnd(new \DateTimeImmutable('2026-09-14 UTC'));
        }
        $price = $this->priceWithPeriod($period);
        $reservation = new Reservation();
        $reservation->setStartDate(new \DateTime('2026-09-12 UTC'));
        $reservation->setEndDate(new \DateTime('2026-09-13 UTC'));
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('getRepository');
        $service = new PriceService($entityManager, new ReservationPeriodService());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Price periods must have a start and end date.');

        $service->getPricesForReservationDays($reservation, 1, new ArrayCollection([$price]));
    }

    /** @return iterable<string, array{string}> */
    public static function missingPriceBoundaries(): iterable
    {
        yield 'start missing' => ['start'];
        yield 'end missing' => ['end'];
    }

    private function priceWithPeriod(PricePeriod $period): Price
    {
        $price = new Price();
        $price->setAllPeriods(false);
        $price->setAllDays(true);
        $price->addPricePeriod($period);

        return $price;
    }
}
