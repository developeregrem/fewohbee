<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use App\Entity\Reservation;
use App\Service\Api\ReservationTypeClassifier;
use App\Service\FrontdeskViewService;
use PHPUnit\Framework\TestCase;

final class ReservationTypeClassifierTest extends TestCase
{
    private ReservationTypeClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new ReservationTypeClassifier();
    }

    public function testSingleDayRangeArrival(): void
    {
        $reservation = $this->buildReservation('2026-01-10', '2026-01-13');
        self::assertSame(['arrival'], $this->classify($reservation, '2026-01-10', '2026-01-10'));
    }

    public function testSingleDayRangeInhouse(): void
    {
        $reservation = $this->buildReservation('2026-01-10', '2026-01-13');
        self::assertSame(['inhouse'], $this->classify($reservation, '2026-01-11', '2026-01-11'));
    }

    public function testSingleDayRangeDeparture(): void
    {
        $reservation = $this->buildReservation('2026-01-10', '2026-01-13');
        self::assertSame(['departure'], $this->classify($reservation, '2026-01-13', '2026-01-13'));
    }

    public function testOneNightStayIsNeverInhouse(): void
    {
        $reservation = $this->buildReservation('2026-01-10', '2026-01-11');
        self::assertSame(['arrival'], $this->classify($reservation, '2026-01-10', '2026-01-10'));
        self::assertSame(['departure'], $this->classify($reservation, '2026-01-11', '2026-01-11'));
        self::assertSame(['arrival', 'departure'], $this->classify($reservation, '2026-01-10', '2026-01-11'));
    }

    public function testRangeCoveringWholeStay(): void
    {
        $reservation = $this->buildReservation('2026-01-10', '2026-01-13');
        self::assertSame(['arrival', 'departure', 'inhouse'], $this->classify($reservation, '2026-01-01', '2026-01-31'));
    }

    public function testStaySpanningRangeIsOnlyInhouse(): void
    {
        $reservation = $this->buildReservation('2026-01-01', '2026-01-31');
        self::assertSame(['inhouse'], $this->classify($reservation, '2026-01-10', '2026-01-12'));
    }

    public function testStayOutsideRange(): void
    {
        $reservation = $this->buildReservation('2026-02-10', '2026-02-13');
        self::assertSame([], $this->classify($reservation, '2026-01-01', '2026-01-31'));
    }

    /**
     * The classifier must be equivalent to FrontdeskViewService::buildItems() for single-day ranges.
     */
    public function testEquivalenceWithFrontdeskViewForSingleDays(): void
    {
        $frontdesk = new FrontdeskViewService();
        $apartment = new Appartment();
        $apartment->setId(1);
        $apartment->setNumber('A1');
        $apartment->setBedsMax(2);

        $stays = [
            ['2026-01-10', '2026-01-13'], // regular stay
            ['2026-01-10', '2026-01-11'], // one-night stay
            ['2026-01-01', '2026-01-31'], // long stay
        ];
        $days = ['2026-01-09', '2026-01-10', '2026-01-11', '2026-01-12', '2026-01-13', '2026-01-14', '2026-01-31'];

        foreach ($stays as [$start, $end]) {
            $reservation = $this->buildReservation($start, $end);
            $reservation->setId(1);
            $reservation->setAppartment($apartment);
            foreach ($days as $day) {
                $date = new \DateTimeImmutable($day);
                $items = $frontdesk->buildItems(
                    [['apartment' => $apartment, 'apartmentReservations' => [$reservation]]],
                    $date,
                    ['arrival', 'departure', 'inhouse']
                );
                $expected = [] !== $items ? $items[0]['categories'] : [];
                $actual = $this->classifier->classify($reservation, $date, $date);
                sort($expected);
                sort($actual);
                self::assertSame($expected, $actual, sprintf('Mismatch for stay %s..%s on %s', $start, $end, $day));
            }
        }
    }

    /**
     * @return list<string>
     */
    private function classify(Reservation $reservation, string $start, string $end): array
    {
        return $this->classifier->classify($reservation, new \DateTimeImmutable($start), new \DateTimeImmutable($end));
    }

    private function buildReservation(string $start, string $end): Reservation
    {
        $reservation = new Reservation();
        $reservation->setStartDate(new \DateTime($start));
        $reservation->setEndDate(new \DateTime($end));

        return $reservation;
    }
}
