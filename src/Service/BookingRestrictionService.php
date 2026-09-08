<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\BookingRestriction\DayRestrictions;
use App\Dto\BookingRestriction\StayRestrictionResult;
use App\Entity\BookingRestrictionRule;
use App\Entity\Enum\BookingRestrictionType;
use App\Entity\RoomCategory;
use App\Exception\InvalidReservationPeriodException;
use App\Repository\BookingRestrictionRuleRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The single resolver for booking restrictions, shared by the public booking flow, the
 * settings preview and — later — the channel export. It turns stored rules into per-date
 * values and answers whether one concrete stay is allowed.
 */
class BookingRestrictionService implements ResetInterface
{
    /** Stay lengths shown as columns in the settings effect table. */
    public const MATRIX_NIGHTS = 7;

    /** @var array<string, list<BookingRestrictionRule>> */
    private array $rulesByPeriod = [];

    public function __construct(
        private readonly BookingRestrictionRuleRepository $repository,
        private readonly ReservationPeriodService $periodService,
    ) {
    }

    /**
     * Validates one stay against all four restriction types:
     * arrival closure at A, departure closure at D, the arrival minimum at A, and the
     * night minimum of every occupied night in [A, D). The departure day is never an
     * occupied night, but it is still checked for a departure closure.
     *
     * @param list<BookingRestrictionRule>|null $rules explicit rules for an unsaved preview; null loads persisted rules
     *
     * @throws InvalidReservationPeriodException for empty, reversed or excessive periods
     */
    public function checkStay(RoomCategory $category, \DateTimeImmutable $arrival, \DateTimeImmutable $departure, ?array $rules = null): StayRestrictionResult
    {
        $period = $this->periodService->validate($arrival, $departure);
        if ($period->start >= $period->end) {
            throw new InvalidReservationPeriodException('reservation.period.invalid_dates');
        }

        // One day beyond the last night, so the departure day carries its own closure state.
        $days = $this->getDailyRestrictions($category, $period->start, $period->end->modify('+1 day'), $rules);
        $nights = count($days) - 1;

        $first = $days[$period->start->format('Y-m-d')];
        $last = $days[$period->end->format('Y-m-d')];

        // The night minima are the only value the departure day must not contribute.
        $required = $first->minStayArrival;
        $reason = BookingRestrictionType::MIN_STAY_ARRIVAL;
        $rule = $first->arrivalRule;
        $ruleDate = $first->date;

        foreach ($days as $day) {
            if ($day->date >= $period->end) {
                continue;
            }
            if ($day->minStayThrough > $required) {
                $required = $day->minStayThrough;
                $reason = BookingRestrictionType::MIN_STAY_THROUGH;
                $rule = $day->throughRule;
                $ruleDate = $day->date;
            }
        }

        // Closures are absolute: no stay length can satisfy them, so they are reported first.
        if ($first->closedToArrival) {
            return new StayRestrictionResult($nights, $required, BookingRestrictionType::CLOSED_TO_ARRIVAL, $first->closedToArrivalRule, $first->date);
        }
        if ($last->closedToDeparture) {
            return new StayRestrictionResult($nights, $required, BookingRestrictionType::CLOSED_TO_DEPARTURE, $last->closedToDepartureRule, $last->date);
        }
        if ($nights < $required) {
            return new StayRestrictionResult($nights, $required, $reason, $rule, $ruleDate);
        }

        return new StayRestrictionResult($nights, $required);
    }

    /**
     * Resolves every date of a half-open window. Each restriction type is resolved on its
     * own, so a lower night minimum never cancels an arrival minimum. Within the minimum-stay
     * types a dated rule (special period) replaces the unlimited ones — that is what allows a
     * period to *lower* a minimum — and among equals the highest value wins. Closures simply
     * add up: any matching rule closes the day.
     *
     * @param list<BookingRestrictionRule>|null $rules explicit rules for previews; no database work when provided
     *
     * @return non-empty-array<string, DayRestrictions> keyed by Y-m-d over [$from, $to)
     *
     * @throws InvalidReservationPeriodException for an invalid date window
     */
    public function getDailyRestrictions(RoomCategory $category, \DateTimeImmutable $from, \DateTimeImmutable $to, ?array $rules = null): array
    {
        $period = $this->periodService->validate($from, $to);
        if ($period->start >= $period->end) {
            throw new InvalidReservationPeriodException('reservation.period.invalid_dates');
        }

        $key = $period->start->format('Y-m-d').'/'.$period->end->format('Y-m-d');
        $rules ??= $this->rulesByPeriod[$key] ??= $this->repository->findActiveForPeriod($period->start, $period->end);
        $matching = array_filter($rules, static fn (BookingRestrictionRule $rule): bool => $rule->appliesTo($category));

        $result = [];
        for ($date = $period->start; $date < $period->end; $date = $date->modify('+1 day')) {
            $arrivalRule = null;
            $throughRule = null;
            $noArrivalRule = null;
            $noDepartureRule = null;

            foreach ($matching as $rule) {
                if (!$rule->coversDate($date)) {
                    continue;
                }
                match ($rule->getType()) {
                    BookingRestrictionType::MIN_STAY_ARRIVAL => $arrivalRule = $this->chooseMinStay($arrivalRule, $rule),
                    BookingRestrictionType::MIN_STAY_THROUGH => $throughRule = $this->chooseMinStay($throughRule, $rule),
                    BookingRestrictionType::CLOSED_TO_ARRIVAL => $noArrivalRule ??= $rule,
                    BookingRestrictionType::CLOSED_TO_DEPARTURE => $noDepartureRule ??= $rule,
                };
            }

            $result[$date->format('Y-m-d')] = new DayRestrictions(
                $date,
                $arrivalRule?->getMinNights() ?? 1,
                $throughRule?->getMinNights() ?? 1,
                null !== $noArrivalRule,
                null !== $noDepartureRule,
                $arrivalRule,
                $throughRule,
                $noArrivalRule,
                $noDepartureRule,
            );
        }

        return $result;
    }

    /**
     * Builds the effect table shown in the settings: seven arrival days by stay length.
     * The whole table is answered from one rule query, so operators can flip through weeks
     * without a query per cell.
     *
     * @param list<BookingRestrictionRule>|null $rules explicit rules for an unsaved preview
     *
     * @return list<array{date: \DateTimeImmutable, cells: list<StayRestrictionResult>}>
     */
    public function getWeekMatrix(RoomCategory $category, \DateTimeImmutable $weekStart, int $maxNights = self::MATRIX_NIGHTS, ?array $rules = null): array
    {
        $start = $weekStart->setTime(0, 0);
        // The longest stay starting on the last row still has to resolve, plus its departure day.
        $windowEnd = $start->modify(sprintf('+%d days', 7 + $maxNights + 1));
        $rules ??= $this->repository->findActiveForPeriod($start, $windowEnd);

        $rows = [];
        for ($offset = 0; $offset < 7; ++$offset) {
            $arrival = $start->modify(sprintf('+%d days', $offset));
            $cells = [];
            for ($nights = 1; $nights <= $maxNights; ++$nights) {
                $cells[] = $this->checkStay($category, $arrival, $arrival->modify(sprintf('+%d days', $nights)), $rules);
            }
            $rows[] = ['date' => $arrival, 'cells' => $cells];
        }

        return $rows;
    }

    /**
     * A dated rule replaces an unlimited one for the days it covers, even when it lowers the
     * minimum. Among rules of the same kind the strictest value wins — there is no
     * "last saved wins" and no priority number for the operator to maintain.
     */
    private function chooseMinStay(?BookingRestrictionRule $current, BookingRestrictionRule $candidate): BookingRestrictionRule
    {
        if (null === $current) {
            return $candidate;
        }

        if ($candidate->isPeriod() !== $current->isPeriod()) {
            return $candidate->isPeriod() ? $candidate : $current;
        }

        return $candidate->getMinNights() > $current->getMinNights() ? $candidate : $current;
    }

    public function reset(): void
    {
        $this->rulesByPeriod = [];
    }
}
