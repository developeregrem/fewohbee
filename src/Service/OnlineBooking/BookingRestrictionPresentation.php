<?php

declare(strict_types=1);

namespace App\Service\OnlineBooking;

use App\Entity\BookingRestrictionRule;
use App\Repository\BookingRestrictionRuleRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns a stored rule into the plain sentence shown in the settings lists, and finds the
 * rules a new one would collide with. Both exist so the operator never has to reason about
 * precedence in the abstract.
 */
final class BookingRestrictionPresentation
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly BookingRestrictionRuleRepository $repository,
    ) {
    }

    /**
     * One sentence per rule, e.g. "Bei Anreise am Fr, Sa muss die gesamte Buchung
     * mindestens 2 Nächte dauern." A dated rule gets its period appended.
     */
    public function describe(BookingRestrictionRule $rule): string
    {
        $sentence = $this->translator->trans('booking_rules.summary_'.$rule->getType()->value, [
            '%nights%' => $this->describeNights($rule->getMinNights() ?? 1),
            '%days%' => $this->describeDays($rule),
        ]);

        if (!$rule->isPeriod()) {
            return $sentence;
        }

        return $sentence.' '.$this->translator->trans('booking_rules.summary_period', [
            '%from%' => $rule->getStartDate()?->format('d.m.Y'),
            // Storage is half-open; the operator entered the last covered day.
            '%to%' => $rule->getEndDate()?->modify('-1 day')->format('d.m.Y'),
        ]);
    }

    /**
     * Where a calendar value comes from: the general rules, or a special period with its first
     * and last covered day. Storage keeps the end exclusive, the operator entered the last day.
     */
    public function sourceLabel(BookingRestrictionRule $rule): string
    {
        if (!$rule->isPeriod()) {
            return $this->translator->trans('booking_rules.source_general');
        }

        return $this->translator->trans('booking_rules.source_period', [
            '%from%' => $rule->getStartDate()?->format('d.m.Y'),
            '%to%' => $rule->getEndDate()?->modify('-1 day')->format('d.m.Y'),
        ]);
    }

    /**
     * The night count as a phrase ("eine Nacht" / "4 Nächte"), because the sentences read
     * badly with a bare number: one night is a real setting that explicitly releases the
     * restriction, and "mindestens 1 Nächte" is simply wrong.
     */
    public function describeNights(int $nights): string
    {
        // %count% only selects singular or plural; %nights% is the number that gets printed.
        return $this->translator->trans('booking_rules.nights_count', ['%count%' => $nights, '%nights%' => $nights]);
    }

    /**
     * Night rules name the pair ("Mo → Di"), every other type names plain days — the day
     * label alone would leave open whether Monday means the arrival or the night after it.
     */
    public function describeDays(BookingRestrictionRule $rule): string
    {
        $prefix = $rule->getType()->selectsNights() ? 'booking_rules.night_' : 'booking_rules.day_';

        return implode(', ', array_map(
            fn (int $day): string => $this->translator->trans($prefix.$day),
            $rule->getWeekdays(),
        ));
    }

    /**
     * Enabled rules of the same type that share at least one weekday, one date and one
     * category with the candidate. Same type only: a departure closure cannot weaken a
     * minimum stay, so listing it would be noise rather than a warning.
     *
     * @return list<BookingRestrictionRule>
     */
    public function overlappingRules(BookingRestrictionRule $candidate, ?int $excludeId): array
    {
        return array_values(array_filter($this->repository->findForSettings(), static function (BookingRestrictionRule $other) use ($candidate, $excludeId): bool {
            if (!$other->isEnabled()
                || $other->getId() === $excludeId
                || $other->getType() !== $candidate->getType()
                || [] === array_intersect($candidate->getWeekdays(), $other->getWeekdays())) {
                return false;
            }

            // Half-open intervals; a null boundary means "unlimited in that direction".
            if (null !== $candidate->getEndDate() && null !== $other->getStartDate() && $candidate->getEndDate() <= $other->getStartDate()) {
                return false;
            }
            if (null !== $other->getEndDate() && null !== $candidate->getStartDate() && $other->getEndDate() <= $candidate->getStartDate()) {
                return false;
            }

            if ($candidate->isAllCategories() || $other->isAllCategories()) {
                return true;
            }

            foreach ($candidate->getCategories() as $category) {
                if ($other->getCategories()->contains($category)) {
                    return true;
                }
            }

            return false;
        }));
    }
}
