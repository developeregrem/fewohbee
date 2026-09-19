<?php

declare(strict_types=1);

namespace App\Service\OnlineBooking;

use App\Dto\BookingRestriction\DayRestrictions;
use App\Dto\BookingRestriction\StayRestrictionResult;
use App\Entity\BookingRestrictionRule;
use App\Entity\Enum\BookingRestrictionType;
use App\Entity\RoomCategory;
use App\Exception\InvalidReservationPeriodException;
use App\Repository\BookingRestrictionRuleRepository;
use App\Service\ReservationPeriodService;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The single resolver for booking restrictions, shared by the public booking flow, the
 * settings preview and — later — the channel export. It turns stored rules into per-date
 * values and answers whether one concrete stay is allowed.
 *
 * @phpstan-type Requirement array{nights: int, reason: BookingRestrictionType, rule: BookingRestrictionRule|null, date: \DateTimeImmutable}
 */
class BookingRestrictionService implements ResetInterface
{
    /** Highest night count a rule accepts; see BookingRestrictionRule::setMinNights(). */
    private const MAX_RULE_NIGHTS = 365;

    /** Departure days tried beyond the longest minimum, so a weekly departure closure cannot hide the shortest stay. */
    private const DEPARTURE_WEEK = 7;

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
        $days = array_values($this->getDailyRestrictions($category, $period->start, $period->end->modify('+1 day'), $rules));
        $nights = count($days) - 1;

        // The night minima are the only value the departure day must not contribute.
        $requirement = $this->arrivalRequirement($days[0]);
        for ($night = 0; $night < $nights; ++$night) {
            $requirement = $this->raiseForNight($requirement, $days[$night]);
        }

        return $this->decide($days[0], $days[$nights], $nights, $requirement);
    }

    /**
     * Resolves every date of a half-open window. Each restriction type is resolved on its
     * own, so a lower night minimum never cancels an arrival minimum. A special period
     * replaces the unlimited minimum-stay rules of *both* kinds for the days it covers — that
     * is what allows a period to lower a minimum — and among the rules left in play the
     * highest value wins. Closures simply add up: any matching rule closes the day, and a
     * period never lifts one.
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

            // A special period states the minimum stays for its days on its own. As soon as
            // one covers this date, the unlimited minimum-stay rules step aside entirely —
            // across both kinds, not just the matching one. Otherwise a period lowering the
            // arrival minimum would still be overruled by a general night rule, which is not
            // what "special period" means to anyone reading it.
            $periodOverridesMinStay = false;
            foreach ($matching as $rule) {
                if ($rule->isPeriod() && $rule->getType()->needsMinNights() && $rule->coversDate($date)) {
                    $periodOverridesMinStay = true;
                    break;
                }
            }

            $arrivalOverridden = [];
            $throughOverridden = [];
            foreach ($matching as $rule) {
                if (!$rule->coversDate($date)) {
                    continue;
                }
                $type = $rule->getType();
                if ($type->needsMinNights() && $periodOverridesMinStay && !$rule->isPeriod()) {
                    // Kept so the settings calendar can name the general rule a period replaces.
                    if (BookingRestrictionType::MIN_STAY_ARRIVAL === $type) {
                        $arrivalOverridden[] = $rule;
                    } else {
                        $throughOverridden[] = $rule;
                    }
                    continue;
                }
                match ($type) {
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
                $arrivalOverridden,
                $throughOverridden,
            );
        }

        return $result;
    }

    /**
     * Checks every stay length for every arrival day of a window, as the settings calendar
     * shows them. Lengths run until every minimum stay a rule demands is reached and a further
     * week of departure days has been tried, so the shortest bookable stay is always among
     * them. The days are resolved once and each length extends the previous one by a night,
     * so the whole window costs one rule query however long the stays get.
     *
     * @param list<BookingRestrictionRule>|null $rules explicit rules; null loads the persisted ones
     *
     * @return array<string, non-empty-list<StayRestrictionResult>> keyed by arrival Y-m-d over [$from, $to), each ordered by length from one night
     *
     * @throws InvalidReservationPeriodException for an invalid date window
     */
    public function getArrivalStayOptions(RoomCategory $category, \DateTimeImmutable $from, \DateTimeImmutable $to, ?array $rules = null): array
    {
        $period = $this->periodService->validate($from, $to);
        if ($period->start >= $period->end) {
            throw new InvalidReservationPeriodException('reservation.period.invalid_dates');
        }

        // Loaded for the longest stay any rule could demand, so the horizon below never
        // depends on a rule this query left out.
        $rules ??= $this->repository->findActiveForPeriod(
            $period->start,
            $period->end->modify(sprintf('+%d days', self::MAX_RULE_NIGHTS + self::DEPARTURE_WEEK + 1)),
        );
        $maxNights = $this->longestMinimum($rules, $category) + self::DEPARTURE_WEEK;
        $days = array_values($this->getDailyRestrictions($category, $period->start, $period->end->modify(sprintf('+%d days', $maxNights + 1)), $rules));

        $result = [];
        for ($first = 0; $days[$first]->date < $period->end; ++$first) {
            $arrival = $days[$first];
            $requirement = $this->arrivalRequirement($arrival);
            $options = [];
            for ($nights = 1; $nights <= $maxNights; ++$nights) {
                $requirement = $this->raiseForNight($requirement, $days[$first + $nights - 1]);
                $options[] = $this->decide($arrival, $days[$first + $nights], $nights, $requirement);
            }
            $result[$arrival->date->format('Y-m-d')] = $options;
        }

        return $result;
    }

    /**
     * What the arrival day alone demands; the occupied nights can only raise it.
     *
     * @return Requirement
     */
    private function arrivalRequirement(DayRestrictions $arrival): array
    {
        return [
            'nights' => $arrival->minStayArrival,
            'reason' => BookingRestrictionType::MIN_STAY_ARRIVAL,
            'rule' => $arrival->arrivalRule,
            'date' => $arrival->date,
        ];
    }

    /**
     * A night takes over only with a strictly higher minimum, so on a tie the rule met first
     * stays the one that is named.
     *
     * @param Requirement $requirement
     *
     * @return Requirement
     */
    private function raiseForNight(array $requirement, DayRestrictions $night): array
    {
        if ($night->minStayThrough <= $requirement['nights']) {
            return $requirement;
        }

        return [
            'nights' => $night->minStayThrough,
            'reason' => BookingRestrictionType::MIN_STAY_THROUGH,
            'rule' => $night->throughRule,
            'date' => $night->date,
        ];
    }

    /**
     * @param Requirement $requirement the strictest minimum over the arrival and every occupied night
     */
    private function decide(DayRestrictions $arrival, DayRestrictions $departure, int $nights, array $requirement): StayRestrictionResult
    {
        // Closures are absolute: no stay length can satisfy them, so they are reported first.
        if ($arrival->closedToArrival) {
            return new StayRestrictionResult($nights, $requirement['nights'], BookingRestrictionType::CLOSED_TO_ARRIVAL, $arrival->closedToArrivalRule, $arrival->date);
        }
        if ($departure->closedToDeparture) {
            return new StayRestrictionResult($nights, $requirement['nights'], BookingRestrictionType::CLOSED_TO_DEPARTURE, $departure->closedToDepartureRule, $departure->date);
        }
        if ($nights < $requirement['nights']) {
            return new StayRestrictionResult($nights, $requirement['nights'], $requirement['reason'], $requirement['rule'], $requirement['date']);
        }

        return new StayRestrictionResult($nights, $requirement['nights']);
    }

    /** @param list<BookingRestrictionRule> $rules */
    private function longestMinimum(array $rules, RoomCategory $category): int
    {
        $longest = 1;
        foreach ($rules as $rule) {
            if ($rule->getType()->needsMinNights() && $rule->appliesTo($category)) {
                $longest = max($longest, (int) $rule->getMinNights());
            }
        }

        return $longest;
    }

    /**
     * The strictest of the rules that are still in play wins. Which layer is in play was
     * decided before this point, so there is no "last saved wins" and no priority number
     * for the operator to maintain.
     */
    private function chooseMinStay(?BookingRestrictionRule $current, BookingRestrictionRule $candidate): BookingRestrictionRule
    {
        if (null === $current) {
            return $candidate;
        }

        return $candidate->getMinNights() > $current->getMinNights() ? $candidate : $current;
    }

    public function reset(): void
    {
        $this->rulesByPeriod = [];
    }
}
