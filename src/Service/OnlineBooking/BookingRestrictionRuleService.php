<?php

declare(strict_types=1);

namespace App\Service\OnlineBooking;

use App\Dto\BookingRestriction\RuleData;
use App\Entity\BookingRestrictionRule;
use App\Repository\BookingRestrictionRuleRepository;
use Doctrine\ORM\EntityManagerInterface;

/** Persists validated booking rules and assembles detached rule sets for unsaved previews. */
final class BookingRestrictionRuleService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BookingRestrictionRuleRepository $repository,
        private readonly BookingRestrictionService $restrictions,
    ) {
    }

    /**
     * Builds or mutates a rule from already validated form input. Fields the chosen type
     * does not use are cleared here, so a rule never carries a stale night count or a
     * date range left over from an earlier edit. Does not flush.
     */
    public function apply(RuleData $data, ?BookingRestrictionRule $rule = null): BookingRestrictionRule
    {
        $rule ??= new BookingRestrictionRule();
        $rule->setType($data->type);
        $rule->setMinNights($data->type->needsMinNights() ? $data->minNights : null);
        $rule->setWeekdays($data->weekdays);
        // The operator picks the last covered day; storage is half-open.
        $rule->setPeriod(
            $data->isPeriod ? $data->startDate : null,
            $data->isPeriod ? $data->lastDate?->modify('+1 day') : null,
        );
        $rule->setEnabled($data->enabled);
        $rule->setAllCategories($data->allCategories);
        $rule->setCategories($data->allCategories ? [] : $data->categories);

        return $rule;
    }

    /** Persists a validated change and discards any request-local restriction cache. */
    public function save(RuleData $data, ?BookingRestrictionRule $rule = null): BookingRestrictionRule
    {
        $rule = $this->apply($data, $rule);
        $this->em->persist($rule);
        $this->em->flush();
        $this->restrictions->reset();

        return $rule;
    }

    /** Deletes the rule and invalidates previously resolved windows. */
    public function delete(BookingRestrictionRule $rule): void
    {
        $this->em->remove($rule);
        $this->em->flush();
        $this->restrictions->reset();
    }

    /** Changes activation without changing the stored scope or values. */
    public function toggle(BookingRestrictionRule $rule): void
    {
        $rule->setEnabled(!$rule->isEnabled());
        $this->em->flush();
        $this->restrictions->reset();
    }

    /**
     * The saved rules with the edited one replaced by a detached draft, so the effect table
     * can show unsaved changes without persisting anything.
     *
     * @return list<BookingRestrictionRule>
     */
    public function previewRules(RuleData $data, ?BookingRestrictionRule $original): array
    {
        $rules = array_values(array_filter(
            $this->repository->findForSettings(),
            static fn (BookingRestrictionRule $rule): bool => null === $original || $rule->getId() !== $original->getId(),
        ));
        $rules[] = $this->apply($data);

        return $rules;
    }
}
