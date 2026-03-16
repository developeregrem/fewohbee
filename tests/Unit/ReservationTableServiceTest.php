<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Dto\ReservationTable\TableCell;
use App\Entity\Appartment;
use App\Entity\Customer;
use App\Entity\CustomerAddresses;
use App\Entity\Reservation;
use App\Entity\ReservationStatus;
use App\Service\ReservationTableService;
use PHPUnit\Framework\TestCase;

final class ReservationTableServiceTest extends TestCase
{
    private ReservationTableService $service;

    protected function setUp(): void
    {
        $this->service = new ReservationTableService();
    }

    // ── Header Tests ──────────────────────────────────────────────────

    public function testMonthHeadersSingleMonth(): void
    {
        $start = new \DateTimeImmutable('2024-03-01');
        $days = $this->service->buildDays($start, 10);
        $headers = $this->service->buildMonthHeaders($days);

        self::assertCount(1, $headers);
        self::assertSame('3', $headers[0]->label);
        self::assertSame(11, $headers[0]->colspan); // 0..10 = 11 days (template multiplies by 2)
    }

    public function testMonthHeadersSpanningTwoMonths(): void
    {
        $start = new \DateTimeImmutable('2024-03-28');
        $days = $this->service->buildDays($start, 7);
        $headers = $this->service->buildMonthHeaders($days);

        self::assertCount(2, $headers);
        self::assertSame('3', $headers[0]->label);
        self::assertSame(4, $headers[0]->colspan); // Mar 28,29,30,31
        self::assertSame('4', $headers[1]->label);
        self::assertSame(4, $headers[1]->colspan); // Apr 1,2,3,4
    }

    public function testWeekHeaders(): void
    {
        // Monday 2024-03-04 to Sunday 2024-03-10 = 1 week
        $start = new \DateTimeImmutable('2024-03-04');
        $days = $this->service->buildDays($start, 6);
        $headers = $this->service->buildWeekHeaders($days);

        self::assertCount(1, $headers);
        self::assertSame(7, $headers[0]->colspan);
    }

    public function testWeekHeadersSpanningTwoWeeks(): void
    {
        $start = new \DateTimeImmutable('2024-03-04');
        $days = $this->service->buildDays($start, 9);
        $headers = $this->service->buildWeekHeaders($days);

        self::assertCount(2, $headers);
        self::assertSame(7, $headers[0]->colspan);
        self::assertSame(3, $headers[1]->colspan);
    }

    public function testDayColumnsWeekendDetection(): void
    {
        // 2024-03-08 = Friday, 2024-03-09 = Saturday, 2024-03-10 = Sunday
        $start = new \DateTimeImmutable('2024-03-08');
        $days = $this->service->buildDays($start, 2);
        $columns = $this->service->buildDayColumns($days);

        self::assertFalse($columns[0]->isWeekend); // Friday
        self::assertTrue($columns[1]->isWeekend);  // Saturday
        self::assertTrue($columns[2]->isWeekend);  // Sunday
    }

    // ── Empty Row Tests ───────────────────────────────────────────────

    public function testEmptyRowProducesAllEmptyCells(): void
    {
        $start = new \DateTimeImmutable('2024-03-01');
        $days = $this->service->buildDays($start, 4);
        $cells = $this->service->buildCellsForRow($days, [], $start, 4);

        // 5 days × 2 half-day slots = 10 empty cells
        self::assertCount(10, $cells);
        foreach ($cells as $i => $cell) {
            self::assertSame(TableCell::TYPE_EMPTY, $cell->type);
            self::assertSame(1, $cell->span);
            self::assertSame($i % 2 === 0 ? 'left' : 'right', $cell->side);
        }
    }

    // ── Single Reservation Tests (half-day model) ─────────────────────

    public function testSimpleReservationFullyInPeriod(): void
    {
        $start = new \DateTimeImmutable('2024-03-01');
        // Reservation: Mar 2 - Mar 4 (2 nights, departure Mar 4)
        $res = self::makeReservation(1, '2024-03-02', '2024-03-04');

        $days = $this->service->buildDays($start, 5);
        $cells = $this->service->buildCellsForRow($days, [$res], $start, 5);

        // Mar 1: 2 empty (left+right)
        self::assertSame(TableCell::TYPE_EMPTY, $cells[0]->type);
        self::assertSame('left', $cells[0]->side);
        self::assertSame(TableCell::TYPE_EMPTY, $cells[1]->type);
        self::assertSame('right', $cells[1]->side);

        // Mar 2 left: empty (arrival half-day free)
        self::assertSame(TableCell::TYPE_EMPTY, $cells[2]->type);
        self::assertSame('left', $cells[2]->side);

        // Reservation: right(Mar 2) + both(Mar 3) + left(Mar 4) = span 4
        self::assertSame(TableCell::TYPE_RESERVATION, $cells[3]->type);
        self::assertSame(4, $cells[3]->span);
        self::assertSame(1, $cells[3]->reservationId);
        self::assertSame(TableCell::POS_FULL, $cells[3]->position);

        // Mar 4 right: empty (departure half-day free)
        self::assertSame(TableCell::TYPE_EMPTY, $cells[4]->type);
        self::assertSame('right', $cells[4]->side);

        // Mar 5: 2 empty, Mar 6: 2 empty
        self::assertSame(TableCell::TYPE_EMPTY, $cells[5]->type);
        self::assertSame(TableCell::TYPE_EMPTY, $cells[6]->type);
        self::assertSame(TableCell::TYPE_EMPTY, $cells[7]->type);
        self::assertSame(TableCell::TYPE_EMPTY, $cells[8]->type);
    }

    public function testReservationStartsBeforePeriod(): void
    {
        $start = new \DateTimeImmutable('2024-03-05');
        // Reservation: Mar 1 - Mar 7, period Mar 5 - Mar 9
        $res = self::makeReservation(1, '2024-03-01', '2024-03-07');

        $days = $this->service->buildDays($start, 4);
        $cells = $this->service->buildCellsForRow($days, [$res], $start, 4);

        // Reservation starts before period → left(Mar 5) is occupied
        // Spans: both(Mar 5) + both(Mar 6) + left(Mar 7) = 5 half-slots
        self::assertSame(TableCell::TYPE_RESERVATION, $cells[0]->type);
        self::assertSame(5, $cells[0]->span);
        self::assertSame(TableCell::POS_END, $cells[0]->position);

        // Mar 7 right: empty
        self::assertSame(TableCell::TYPE_EMPTY, $cells[1]->type);
        self::assertSame('right', $cells[1]->side);

        // Mar 8: 2 empty, Mar 9: 2 empty
        self::assertSame(TableCell::TYPE_EMPTY, $cells[2]->type);
        self::assertSame(TableCell::TYPE_EMPTY, $cells[3]->type);
        self::assertSame(TableCell::TYPE_EMPTY, $cells[4]->type);
        self::assertSame(TableCell::TYPE_EMPTY, $cells[5]->type);
    }

    public function testReservationEndsAfterPeriod(): void
    {
        $start = new \DateTimeImmutable('2024-03-01');
        // Reservation: Mar 3 - Mar 10, period ends Mar 5
        $res = self::makeReservation(1, '2024-03-03', '2024-03-10');

        $days = $this->service->buildDays($start, 4);
        $cells = $this->service->buildCellsForRow($days, [$res], $start, 4);

        // Mar 1: 2 empty, Mar 2: 2 empty
        self::assertSame(TableCell::TYPE_EMPTY, $cells[0]->type);
        self::assertSame(TableCell::TYPE_EMPTY, $cells[1]->type);
        self::assertSame(TableCell::TYPE_EMPTY, $cells[2]->type);
        self::assertSame(TableCell::TYPE_EMPTY, $cells[3]->type);

        // Mar 3 left: empty (arrival day)
        self::assertSame(TableCell::TYPE_EMPTY, $cells[4]->type);
        self::assertSame('left', $cells[4]->side);

        // Reservation: right(Mar 3) + both(Mar 4) + both(Mar 5) = 5 half-slots
        self::assertSame(TableCell::TYPE_RESERVATION, $cells[5]->type);
        self::assertSame(5, $cells[5]->span);
        self::assertSame(TableCell::POS_START, $cells[5]->position);
    }

    public function testReservationSpansEntirePeriod(): void
    {
        $start = new \DateTimeImmutable('2024-03-05');
        // Reservation: Mar 1 - Mar 15, period is Mar 5 - Mar 9
        $res = self::makeReservation(1, '2024-03-01', '2024-03-15');

        $days = $this->service->buildDays($start, 4);
        $cells = $this->service->buildCellsForRow($days, [$res], $start, 4);

        // Both start and end are outside period → all slots occupied
        self::assertCount(1, $cells);
        self::assertSame(TableCell::TYPE_RESERVATION, $cells[0]->type);
        self::assertSame(10, $cells[0]->span); // 5 days × 2 = 10 half-slots
        self::assertSame(TableCell::POS_MIDDLE, $cells[0]->position);
    }

    // ── Turnover Tests (natural with half-day model) ──────────────────

    public function testTurnoverDay(): void
    {
        $start = new \DateTimeImmutable('2024-03-01');
        // Res 1: Mar 1 - Mar 3, Res 2: Mar 3 - Mar 5
        $res1 = self::makeReservation(1, '2024-03-01', '2024-03-03');
        $res2 = self::makeReservation(2, '2024-03-03', '2024-03-05');

        $days = $this->service->buildDays($start, 5);
        $cells = $this->service->buildCellsForRow($days, [$res1, $res2], $start, 5);

        // Mar 1 left: empty (arrival)
        self::assertSame(TableCell::TYPE_EMPTY, $cells[0]->type);
        self::assertSame('left', $cells[0]->side);

        // Res1: right(Mar 1) + both(Mar 2) + left(Mar 3) = 4 half-slots
        self::assertSame(TableCell::TYPE_RESERVATION, $cells[1]->type);
        self::assertSame(4, $cells[1]->span);
        self::assertSame(1, $cells[1]->reservationId);

        // Res2: right(Mar 3) + both(Mar 4) + left(Mar 5) = 4 half-slots
        // Turnover on Mar 3 is natural: left = Res1 end, right = Res2 start
        self::assertSame(TableCell::TYPE_RESERVATION, $cells[2]->type);
        self::assertSame(4, $cells[2]->span);
        self::assertSame(2, $cells[2]->reservationId);

        // Mar 5 right: empty
        self::assertSame(TableCell::TYPE_EMPTY, $cells[3]->type);

        // Mar 6: 2 empty
        self::assertSame(TableCell::TYPE_EMPTY, $cells[4]->type);
        self::assertSame(TableCell::TYPE_EMPTY, $cells[5]->type);
    }

    public function testThreeConsecutiveReservationsWithTurnovers(): void
    {
        $start = new \DateTimeImmutable('2024-03-01');
        $res1 = self::makeReservation(1, '2024-03-01', '2024-03-03');
        $res2 = self::makeReservation(2, '2024-03-03', '2024-03-05');
        $res3 = self::makeReservation(3, '2024-03-05', '2024-03-07');

        $days = $this->service->buildDays($start, 7);
        $cells = $this->service->buildCellsForRow($days, [$res1, $res2, $res3], $start, 7);

        // Mar 1 left: empty (arrival Res1)
        self::assertSame(TableCell::TYPE_EMPTY, $cells[0]->type);

        // Res1: right(1) + both(2) + left(3) = 4
        self::assertSame(TableCell::TYPE_RESERVATION, $cells[1]->type);
        self::assertSame(4, $cells[1]->span);
        self::assertSame(1, $cells[1]->reservationId);

        // Res2: right(3) + both(4) + left(5) = 4
        self::assertSame(TableCell::TYPE_RESERVATION, $cells[2]->type);
        self::assertSame(4, $cells[2]->span);
        self::assertSame(2, $cells[2]->reservationId);

        // Res3: right(5) + both(6) + left(7) = 4
        self::assertSame(TableCell::TYPE_RESERVATION, $cells[3]->type);
        self::assertSame(4, $cells[3]->span);
        self::assertSame(3, $cells[3]->reservationId);

        // Mar 7 right: empty
        self::assertSame(TableCell::TYPE_EMPTY, $cells[4]->type);

        // Mar 8: 2 empty
        self::assertSame(TableCell::TYPE_EMPTY, $cells[5]->type);
        self::assertSame(TableCell::TYPE_EMPTY, $cells[6]->type);
    }

    // ── Single-Day Reservation Tests ──────────────────────────────────

    public function testSingleDayReservation(): void
    {
        $start = new \DateTimeImmutable('2024-03-01');
        $res = self::makeReservation(1, '2024-03-02', '2024-03-02');

        $days = $this->service->buildDays($start, 3);
        $cells = $this->service->buildCellsForRow($days, [$res], $start, 3);

        // Mar 1: 2 empty
        self::assertSame(TableCell::TYPE_EMPTY, $cells[0]->type);
        self::assertSame(TableCell::TYPE_EMPTY, $cells[1]->type);

        // Mar 2: single-day reservation occupies both halves (span 2)
        self::assertSame(TableCell::TYPE_RESERVATION, $cells[2]->type);
        self::assertSame(TableCell::POS_SINGLE, $cells[2]->position);
        self::assertSame(2, $cells[2]->span);

        // Mar 3: 2 empty, Mar 4: 2 empty
        self::assertSame(TableCell::TYPE_EMPTY, $cells[3]->type);
        self::assertSame(TableCell::TYPE_EMPTY, $cells[4]->type);
        self::assertSame(TableCell::TYPE_EMPTY, $cells[5]->type);
        self::assertSame(TableCell::TYPE_EMPTY, $cells[6]->type);
    }

    public function testTwoSingleDayReservationsOnConsecutiveDays(): void
    {
        $start = new \DateTimeImmutable('2024-03-01');
        $res1 = self::makeReservation(1, '2024-03-02', '2024-03-02');
        $res2 = self::makeReservation(2, '2024-03-03', '2024-03-03');

        $days = $this->service->buildDays($start, 3);
        $cells = $this->service->buildCellsForRow($days, [$res1, $res2], $start, 3);

        // Mar 1: 2 empty
        self::assertSame(TableCell::TYPE_EMPTY, $cells[0]->type);
        self::assertSame(TableCell::TYPE_EMPTY, $cells[1]->type);

        // Mar 2: single-day reservation (span 2)
        self::assertSame(TableCell::TYPE_RESERVATION, $cells[2]->type);
        self::assertSame(TableCell::POS_SINGLE, $cells[2]->position);
        self::assertSame(2, $cells[2]->span);
        self::assertSame(1, $cells[2]->reservationId);

        // Mar 3: single-day reservation (span 2)
        self::assertSame(TableCell::TYPE_RESERVATION, $cells[3]->type);
        self::assertSame(TableCell::POS_SINGLE, $cells[3]->position);
        self::assertSame(2, $cells[3]->span);
        self::assertSame(2, $cells[3]->reservationId);

        // Mar 4: 2 empty
        self::assertSame(TableCell::TYPE_EMPTY, $cells[4]->type);
        self::assertSame(TableCell::TYPE_EMPTY, $cells[5]->type);
    }

    // ── Half-Day Selectability Tests ──────────────────────────────────

    public function testArrivalDayLeftHalfIsEmpty(): void
    {
        $start = new \DateTimeImmutable('2024-03-01');
        $res = self::makeReservation(1, '2024-03-01', '2024-03-03');

        $days = $this->service->buildDays($start, 3);
        $cells = $this->service->buildCellsForRow($days, [$res], $start, 3);

        // Mar 1 left half: empty (selectable for another reservation)
        self::assertSame(TableCell::TYPE_EMPTY, $cells[0]->type);
        self::assertSame('left', $cells[0]->side);
        self::assertSame('2024-03-01', $cells[0]->date);
    }

    public function testDepartureDayRightHalfIsEmpty(): void
    {
        $start = new \DateTimeImmutable('2024-03-01');
        $res = self::makeReservation(1, '2024-03-01', '2024-03-03');

        $days = $this->service->buildDays($start, 3);
        $cells = $this->service->buildCellsForRow($days, [$res], $start, 3);

        // Find the empty cell after the reservation (Mar 3 right half)
        $lastResCellIndex = null;
        foreach ($cells as $i => $cell) {
            if ($cell->type === TableCell::TYPE_RESERVATION) {
                $lastResCellIndex = $i;
            }
        }
        $afterRes = $cells[$lastResCellIndex + 1];
        self::assertSame(TableCell::TYPE_EMPTY, $afterRes->type);
        self::assertSame('right', $afterRes->side);
        self::assertSame('2024-03-03', $afterRes->date);
    }

    // ── Multiple Occupancy Tests ──────────────────────────────────────

    public function testMultipleOccupancyNoOverlap(): void
    {
        $res1 = self::makeReservation(1, '2024-03-01', '2024-03-03');
        $res2 = self::makeReservation(2, '2024-03-05', '2024-03-07');

        $rows = $this->service->resolveMultipleOccupancy([$res1, $res2]);

        self::assertCount(1, $rows);
        self::assertCount(2, $rows[0]);
    }

    public function testMultipleOccupancyWithOverlap(): void
    {
        $res1 = self::makeReservation(1, '2024-03-01', '2024-03-05');
        $res2 = self::makeReservation(2, '2024-03-03', '2024-03-07');

        $rows = $this->service->resolveMultipleOccupancy([$res1, $res2]);

        self::assertCount(2, $rows);
        self::assertCount(1, $rows[0]);
        self::assertCount(1, $rows[1]);
    }

    public function testMultipleOccupancyThreeOverlapping(): void
    {
        $res1 = self::makeReservation(1, '2024-03-01', '2024-03-10');
        $res2 = self::makeReservation(2, '2024-03-03', '2024-03-07');
        $res3 = self::makeReservation(3, '2024-03-05', '2024-03-12');

        $rows = $this->service->resolveMultipleOccupancy([$res1, $res2, $res3]);

        self::assertCount(3, $rows);
    }

    public function testMultipleOccupancySequentialFitsInOneRow(): void
    {
        // Res1 ends on Mar 3, Res2 starts on Mar 3 → same row (turnover)
        $res1 = self::makeReservation(1, '2024-03-01', '2024-03-03');
        $res2 = self::makeReservation(2, '2024-03-03', '2024-03-05');

        $rows = $this->service->resolveMultipleOccupancy([$res1, $res2]);

        self::assertCount(1, $rows);
        self::assertCount(2, $rows[0]);
    }

    // ── Display Name Tests ────────────────────────────────────────────

    public function testDisplayNameFromBookerLastnameFirstname(): void
    {
        $res = self::makeReservation(1, '2024-03-01', '2024-03-03');
        $booker = new Customer();
        $booker->setLastname('Müller');
        $booker->setFirstname('Hans');
        $booker->setSalutation('Herr');
        $res->setBooker($booker);

        $name = $this->service->getDisplayName($res);

        self::assertSame('Müller, Hans', $name);
    }

    public function testDisplayNameFromBusinessCompany(): void
    {
        $res = self::makeReservation(1, '2024-03-01', '2024-03-03');
        $booker = new Customer();
        $booker->setLastname('Müller');
        $booker->setFirstname('Hans');
        $booker->setSalutation('Herr');

        $address = new CustomerAddresses();
        $address->setType('CUSTOMER_ADDRESS_TYPE_BUSINESS');
        $address->setCompany('ACME GmbH');
        $booker->addCustomerAddress($address);

        $res->setBooker($booker);

        $name = $this->service->getDisplayName($res);

        self::assertSame('ACME GmbH', $name);
    }

    public function testDisplayNameWithoutBooker(): void
    {
        $res = self::makeReservation(1, '2024-03-01', '2024-03-03');

        $name = $this->service->getDisplayName($res);

        self::assertSame('-', $name);
    }

    // ── Full Grid Tests ───────────────────────────────────────────────

    public function testBuildGridWithSubsidiaryBreaks(): void
    {
        $apt1 = self::makeApartment(1, '101');
        $apt2 = self::makeApartment(2, '201');

        $start = new \DateTimeImmutable('2024-03-01');
        $grid = $this->service->buildGrid([$apt1, $apt2], $start, 3, [], true);

        self::assertCount(2, $grid->rows);
        self::assertCount(4, $grid->dayColumns); // 0..3 = 4 days
    }

    public function testBuildGridMultipleOccupancyCreatesSubRows(): void
    {
        $apt = self::makeApartment(1, '101', true);

        $res1 = self::makeReservation(1, '2024-03-01', '2024-03-05');
        $res1->setAppartment($apt);
        $res2 = self::makeReservation(2, '2024-03-03', '2024-03-07');
        $res2->setAppartment($apt);

        $start = new \DateTimeImmutable('2024-03-01');
        $grid = $this->service->buildGrid([$apt], $start, 7, [$res1, $res2]);

        // Should have 2 rows (one per non-overlapping set)
        self::assertCount(2, $grid->rows);
        self::assertFalse($grid->rows[0]->isSubRow);
        self::assertTrue($grid->rows[1]->isSubRow);
    }

    // ── Edge Case: Turnover at period boundary ────────────────────────

    public function testTurnoverAtPeriodStart(): void
    {
        // Period starts Mar 5. Res1 ends Mar 5, Res2 starts Mar 5
        $start = new \DateTimeImmutable('2024-03-05');
        $res1 = self::makeReservation(1, '2024-03-01', '2024-03-05');
        $res2 = self::makeReservation(2, '2024-03-05', '2024-03-08');

        $days = $this->service->buildDays($start, 4);
        $cells = $this->service->buildCellsForRow($days, [$res1, $res2], $start, 4);

        // Mar 5 left: Res1 departure (started before period → occupies left half)
        self::assertSame(TableCell::TYPE_RESERVATION, $cells[0]->type);
        self::assertSame(1, $cells[0]->reservationId);
        self::assertSame(1, $cells[0]->span); // just the left half of Mar 5

        // Mar 5 right → Res2 arrival, continues through Mar 8
        // right(5) + both(6) + both(7) + left(8) = 6 half-slots
        self::assertSame(TableCell::TYPE_RESERVATION, $cells[1]->type);
        self::assertSame(2, $cells[1]->reservationId);
        self::assertSame(6, $cells[1]->span);

        // Mar 8 right: empty
        self::assertSame(TableCell::TYPE_EMPTY, $cells[2]->type);

        // Mar 9: 2 empty
        self::assertSame(TableCell::TYPE_EMPTY, $cells[3]->type);
        self::assertSame(TableCell::TYPE_EMPTY, $cells[4]->type);
    }

    public function testTurnoverAtPeriodEnd(): void
    {
        // Period: Mar 1 - Mar 5. Res1 ends Mar 5, Res2 starts Mar 5
        $start = new \DateTimeImmutable('2024-03-01');
        $res1 = self::makeReservation(1, '2024-03-01', '2024-03-05');
        $res2 = self::makeReservation(2, '2024-03-05', '2024-03-10');

        $days = $this->service->buildDays($start, 4);
        $cells = $this->service->buildCellsForRow($days, [$res1, $res2], $start, 4);

        // Mar 1 left: empty (arrival Res1)
        self::assertSame(TableCell::TYPE_EMPTY, $cells[0]->type);

        // Res1: right(1) + both(2) + both(3) + both(4) + left(5) = 8 half-slots
        self::assertSame(TableCell::TYPE_RESERVATION, $cells[1]->type);
        self::assertSame(1, $cells[1]->reservationId);
        self::assertSame(8, $cells[1]->span);

        // Res2: right(5) = 1 half-slot (ends after period, but only Mar 5 right is visible)
        self::assertSame(TableCell::TYPE_RESERVATION, $cells[2]->type);
        self::assertSame(2, $cells[2]->reservationId);
        self::assertSame(1, $cells[2]->span);
    }

    public function testReservationFillsEntirePeriodIncludingDeparture(): void
    {
        // Reservation Mar 1-3, period Mar 1-3. 3 days visible.
        $start = new \DateTimeImmutable('2024-03-01');
        $res = self::makeReservation(1, '2024-03-01', '2024-03-03');

        $days = $this->service->buildDays($start, 2);
        $cells = $this->service->buildCellsForRow($days, [$res], $start, 2);

        // Mar 1 left: empty (arrival)
        self::assertSame(TableCell::TYPE_EMPTY, $cells[0]->type);
        self::assertSame('left', $cells[0]->side);

        // Res: right(1) + both(2) + left(3) = 4 half-slots
        self::assertSame(TableCell::TYPE_RESERVATION, $cells[1]->type);
        self::assertSame(4, $cells[1]->span);
        self::assertSame(TableCell::POS_FULL, $cells[1]->position);

        // Mar 3 right: empty (departure)
        self::assertSame(TableCell::TYPE_EMPTY, $cells[2]->type);
        self::assertSame('right', $cells[2]->side);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private static function makeReservation(int $id, string $startDate, string $endDate): Reservation
    {
        $status = new ReservationStatus();
        $status->setName('Confirmed');
        $status->setColor('#2d9434');
        $status->setContrastColor('#ffffff');
        $status->setIsBlocking(true);

        $reservation = new Reservation();
        $reservation->setId($id);
        $reservation->setStartDate(new \DateTime($startDate));
        $reservation->setEndDate(new \DateTime($endDate));
        $reservation->setPersons(2);
        $reservation->setReservationStatus($status);

        return $reservation;
    }

    private static function makeApartment(int $id, string $number, bool $multipleOccupancy = false): Appartment
    {
        $apt = new Appartment();
        $apt->setId($id);
        $apt->setNumber($number);
        $apt->setBedsMax(2);
        $apt->setDescription('Test Room '.$number);
        $apt->setMultipleOccupancy($multipleOccupancy);

        return $apt;
    }
}
