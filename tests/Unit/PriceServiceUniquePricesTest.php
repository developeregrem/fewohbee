<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Price;
use App\Entity\Reservation;
use App\Repository\PriceRepository;
use App\Service\PriceService;
use App\Service\ReservationPeriodService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class PriceServiceUniquePricesTest extends TestCase
{
    public function testKeepsFirstSeenOrderAndDeduplicatesByObjectIdentity(): void
    {
        $first = new Price();
        $first->setId(42);
        $first->setAllDays(true);
        $second = new Price();
        $second->setAllDays(true);
        $sameIdDifferentObject = clone $first;

        $emptyStay = $this->reservation();
        $firstStay = $this->reservation();
        $secondStay = $this->reservation();
        $repository = $this->createStub(PriceRepository::class);
        $repository->method('findMiscPrices')->willReturnCallback(
            static fn (Reservation $reservation): array => match ($reservation) {
                $emptyStay => [],
                $firstStay => [$first, $first, $second],
                $secondStay => [$sameIdDifferentObject, $second, $first],
                default => throw new \LogicException('Unexpected reservation in the price lookup.'),
            },
        );
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        $service = new PriceService($em, new ReservationPeriodService());

        $prices = $service->getUniquePricesForReservations([2 => $emptyStay, 5 => $firstStay, 9 => $secondStay], 1);

        self::assertSame([$first, $second, $sameIdDifferentObject], $prices->toArray());
    }

    public function testEmptySelectionDoesNotQueryPrices(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('getRepository');
        $service = new PriceService($em, new ReservationPeriodService());

        self::assertTrue($service->getUniquePricesForReservations([], 1)->isEmpty());
    }

    public function testStayWithoutApplicablePricesReturnsAnEmptyCollection(): void
    {
        $repository = $this->createStub(PriceRepository::class);
        $repository->method('findMiscPrices')->willReturn([]);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        $service = new PriceService($em, new ReservationPeriodService());

        self::assertTrue($service->getUniquePricesForReservations([$this->reservation()], 1)->isEmpty());
    }

    private function reservation(): Reservation
    {
        $reservation = new Reservation();
        $reservation->setStartDate(new \DateTime('2031-06-01'));
        $reservation->setEndDate(new \DateTime('2031-06-03'));

        return $reservation;
    }
}
