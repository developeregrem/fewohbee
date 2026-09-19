<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Reservation;
use App\Exception\InvalidReservationPeriodException;
use App\Service\PriceService;
use App\Service\ReservationPeriodService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Ensures pricing rejects unsafe reservation periods before queries or per-night allocation.
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
}
