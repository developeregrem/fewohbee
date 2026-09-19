<?php

declare(strict_types=1);

namespace App\Service\OnlineBooking;

use App\Dto\BookingRestriction\DayRestrictions;
use App\Dto\BookingRestriction\StayRestrictionResult;
use App\Entity\BookingRestrictionRule;
use App\Entity\Enum\BookingRestrictionType;
use App\Entity\RoomCategory;
use App\Service\OperationsFilterService;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds the booking-rule calendar of the online booking settings: four weeks of resolved
 * daily restrictions for one room category, topped by what all rules add up to for an arrival
 * on each day. It only arranges and words what BookingRestrictionService resolves — no rule is
 * evaluated here.
 */
final class BookingRestrictionCalendar
{
    /** Tab of the online booking settings page that holds the booking rules; also its `?tab=` value. */
    public const SETTINGS_TAB = 'tab-booking-rules';

    /** Four weeks, starting on a Monday like the reservation overview. */
    public const DAYS = 28;

    /** Reasons listed in one tooltip; a longer list stops being read. */
    private const TIP_REASONS = 3;

    public function __construct(
        private readonly BookingRestrictionService $restrictions,
        private readonly BookingRestrictionPresentation $presentation,
        private readonly OperationsFilterService $dates,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param string|null $week any Y-m-d date inside the first week to show; null shows the current week
     *
     * @return array{
     *     category: RoomCategory,
     *     weekStart: \DateTimeImmutable,
     *     previousWeek: \DateTimeImmutable,
     *     nextWeek: \DateTimeImmutable,
     *     currentWeek: \DateTimeImmutable,
     *     today: \DateTimeImmutable,
     *     days: list<DayRestrictions>,
     *     arrivals: list<array{nights: int|null, raised: bool, gap: bool, value: string, reasons: list<array{label: string, text: string, source: string}>}>,
     *     months: list<array{date: \DateTimeImmutable, span: int}>,
     *     rules: array<int, array{source: string, text: string}>
     * }
     */
    public function build(RoomCategory $category, ?string $week): array
    {
        // Rule dates are hydrated in the application time zone; the calendar counts days in the
        // same zone, or a special period could appear to start a day late or early.
        $timezone = new \DateTimeZone(date_default_timezone_get());
        $today = $this->dates->resolveDate(null, $timezone);
        $weekStart = $this->dates->resolveWeekStart($this->dates->resolveStartDate($week, $timezone));
        $windowEnd = $weekStart->modify(sprintf('+%d days', self::DAYS));

        $days = array_values($this->restrictions->getDailyRestrictions($category, $weekStart, $windowEnd));
        $options = $this->restrictions->getArrivalStayOptions($category, $weekStart, $windowEnd);

        $arrivals = [];
        foreach ($days as $day) {
            $arrivals[] = $this->arrival($day, $options[$day->date->format('Y-m-d')] ?? []);
        }

        return [
            'category' => $category,
            'weekStart' => $weekStart,
            'previousWeek' => $weekStart->modify('-7 days'),
            'nextWeek' => $weekStart->modify('+7 days'),
            'currentWeek' => $this->dates->resolveWeekStart($today),
            'today' => $today,
            'days' => $days,
            'arrivals' => $arrivals,
            'months' => $this->months($days),
            'rules' => $this->ruleMap($days),
        ];
    }

    /**
     * The top row of the calendar: from how many nights an arrival on this day is bookable.
     * What the day's own arrival and night rules demand is expected and shown in the rows
     * below; only lengths from there on that still fail are explained. They raise the minimum
     * or, when a longer stay reaches a stricter night, open a gap.
     *
     * @param list<StayRestrictionResult> $options
     *
     * @return array{nights: int|null, raised: bool, gap: bool, value: string, reasons: list<array{label: string, text: string, source: string}>}
     */
    private function arrival(DayRestrictions $day, array $options): array
    {
        if ($day->closedToArrival) {
            return ['nights' => null, 'raised' => false, 'gap' => false, 'value' => $this->translator->trans('booking_rules.calendar_effective_closed'), 'reasons' => []];
        }

        $expected = max($day->minStayArrival, $day->minStayThrough);
        $unexpected = array_values(array_filter(
            $options,
            static fn (StayRestrictionResult $option): bool => !$option->isAllowed() && $option->nights >= $expected,
        ));

        $shortest = null;
        foreach ($options as $option) {
            if ($option->isAllowed()) {
                $shortest = $option->nights;
                break;
            }
        }

        if (null === $shortest) {
            return [
                'nights' => null,
                'raised' => false,
                'gap' => false,
                'value' => $this->translator->trans('booking_rules.calendar_effective_none', ['%count%' => count($options)]),
                'reasons' => $this->reasons($unexpected),
            ];
        }

        // Above the shortest stay only a minimum stay opens a gap; a departure closure there is
        // plain to see in its own row.
        $raisedBy = array_values(array_filter($unexpected, static fn (StayRestrictionResult $option): bool => $option->nights < $shortest));
        $gaps = array_values(array_filter(
            $unexpected,
            static fn (StayRestrictionResult $option): bool => $option->nights > $shortest && true === $option->reason?->needsMinNights(),
        ));

        return [
            'nights' => $shortest,
            'raised' => [] !== $raisedBy,
            'gap' => [] !== $gaps,
            'value' => $this->translator->trans('booking_rules.calendar_effective_from', ['%count%' => $shortest]),
            'reasons' => $this->reasons([...$raisedBy, ...$gaps]),
        ];
    }

    /**
     * Month headers over the day columns, each spanning the days of that month on screen.
     *
     * @param list<DayRestrictions> $days
     *
     * @return list<array{date: \DateTimeImmutable, span: int}>
     */
    private function months(array $days): array
    {
        $months = [];
        foreach ($days as $day) {
            $last = array_key_last($months);
            if (null !== $last && $months[$last]['date']->format('Y-m') === $day->date->format('Y-m')) {
                ++$months[$last]['span'];
                continue;
            }
            $months[] = ['date' => $day->date, 'span' => 1];
        }

        return $months;
    }

    /**
     * Lengths of stay as ranges with their unit, e.g. "1 Nacht" or "2, 4–7 Nächte".
     *
     * @param list<int> $nights ascending
     */
    private function lengths(array $nights): string
    {
        $ranges = [];
        foreach ($nights as $n) {
            $last = array_key_last($ranges);
            if (null !== $last && $n === $ranges[$last][1] + 1) {
                $ranges[$last][1] = $n;
                continue;
            }
            $ranges[] = [$n, $n];
        }

        $text = implode(', ', array_map(
            static fn (array $range): string => $range[0] === $range[1] ? (string) $range[0] : $range[0].'–'.$range[1],
            $ranges,
        ));

        // %count% only picks singular for exactly one night; the printed text carries the ranges.
        return $this->translator->trans('booking_rules.stay_lengths', ['%count%' => [1] === $nights ? 1 : 2, '%lengths%' => $text]);
    }

    /**
     * One tooltip entry per reason lengths of stay fail, merging consecutive lengths that fail
     * for the same rule on the same date ("1–2 Nächte").
     *
     * @param list<StayRestrictionResult> $options
     *
     * @return list<array{label: string, text: string, source: string}>
     */
    private function reasons(array $options): array
    {
        /** @var list<array{key: string, nights: non-empty-list<int>, reason: BookingRestrictionType, rule: BookingRestrictionRule, date: \DateTimeImmutable, required: int}> $groups */
        $groups = [];
        foreach ($options as $option) {
            if ($option->isAllowed() || null === $option->reason || null === $option->rule || null === $option->ruleDate) {
                continue;
            }

            $key = $option->reason->value.'|'.$option->rule->getId().'|'.$option->ruleDate->format('Y-m-d');
            $last = array_key_last($groups);
            if (null !== $last && $groups[$last]['key'] === $key && end($groups[$last]['nights']) === $option->nights - 1) {
                $groups[$last]['nights'][] = $option->nights;
                continue;
            }

            $groups[] = [
                'key' => $key,
                'nights' => [$option->nights],
                'reason' => $option->reason,
                'rule' => $option->rule,
                'date' => $option->ruleDate,
                'required' => $option->requiredNights,
            ];
        }

        return array_map(fn (array $group): array => [
            'label' => $this->translator->trans('booking_rules.calendar_effective_denied', ['%lengths%' => $this->lengths($group['nights'])]),
            'text' => $this->reasonText($group['reason'], $group['date'], $group['required']),
            'source' => $this->presentation->sourceLabel($group['rule']),
        ], array_slice($groups, 0, self::TIP_REASONS));
    }

    private function reasonText(BookingRestrictionType $reason, \DateTimeImmutable $date, int $requiredNights): string
    {
        $weekday = (int) $date->format('N');

        return $this->translator->trans('booking_rules.reason_'.$reason->value, [
            '%day%' => $this->translator->trans('booking_rules.day_'.$weekday),
            '%night%' => $this->translator->trans('booking_rules.night_'.$weekday),
            '%date%' => $date->format('d.m.'),
            '%nights%' => $this->presentation->describeNights($requiredNights),
        ]);
    }

    /**
     * Sentence and origin of every rule the calendar cells refer to, keyed by id, for the
     * tooltips — sent once instead of repeating the sentences on every cell.
     *
     * @param list<DayRestrictions> $days
     *
     * @return array<int, array{source: string, text: string}>
     */
    private function ruleMap(array $days): array
    {
        $rules = [];
        foreach ($days as $day) {
            $referenced = [
                $day->arrivalRule,
                $day->throughRule,
                $day->closedToArrivalRule,
                $day->closedToDepartureRule,
                ...$day->arrivalOverridden,
                ...$day->throughOverridden,
            ];
            foreach ($referenced as $rule) {
                if (null !== $rule) {
                    $rules[(int) $rule->getId()] = $rule;
                }
            }
        }

        return array_map(fn (BookingRestrictionRule $rule): array => [
            'source' => $this->presentation->sourceLabel($rule),
            'text' => $this->presentation->describe($rule),
        ], $rules);
    }
}
