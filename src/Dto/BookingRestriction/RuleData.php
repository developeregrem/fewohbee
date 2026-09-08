<?php

declare(strict_types=1);

namespace App\Dto\BookingRestriction;

use App\Entity\BookingRestrictionRule;
use App\Entity\Enum\BookingRestrictionType;
use App\Entity\RoomCategory;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Form input kept separate from managed entities, so an invalid submission or an unsaved
 * preview can never reach the database.
 *
 * The two date fields are the ones the operator sees: both days are inclusive. The
 * exclusive end used internally is derived when the rule is written.
 */
final class RuleData
{
    public BookingRestrictionType $type = BookingRestrictionType::MIN_STAY_ARRIVAL;

    /**
     * Whether the offcanvas edits a special period. Stored explicitly rather than derived
     * from the dates, so a half-filled period form fails validation instead of silently
     * turning into an unlimited rule.
     */
    public bool $isPeriod = false;

    public ?int $minNights = 1;

    /** @var list<int> ISO weekdays; Sunday is 7. */
    public array $weekdays = [1, 2, 3, 4, 5, 6, 7];

    public ?\DateTimeImmutable $startDate = null;

    /** Last day the rule covers, inclusive. */
    public ?\DateTimeImmutable $lastDate = null;

    public bool $enabled = true;

    public bool $allCategories = true;

    /** @var list<RoomCategory> */
    public array $categories = [];

    public static function fromRule(BookingRestrictionRule $rule): self
    {
        $data = new self();
        $data->type = $rule->getType();
        $data->isPeriod = $rule->isPeriod();
        $data->minNights = $rule->getMinNights();
        $data->weekdays = $rule->getWeekdays();
        $data->startDate = $rule->getStartDate();
        $data->lastDate = $rule->getEndDate()?->modify('-1 day');
        $data->enabled = $rule->isEnabled();
        $data->allCategories = $rule->isAllCategories();
        $data->categories = $rule->getCategories()->getValues();

        return $data;
    }

    /**
     * Validates the inputs that depend on the chosen type. Fields hidden by the current
     * type are not validated — they carry no meaning for the resulting rule.
     */
    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->type->needsMinNights() && (null === $this->minNights || $this->minNights < 1 || $this->minNights > 365)) {
            $context->buildViolation('booking_rules.validation.min_nights')->atPath('minNights')->addViolation();
        }

        if ([] === $this->weekdays) {
            $context->buildViolation('booking_rules.validation.weekdays')->atPath('weekdays')->addViolation();
        }

        if (!$this->allCategories && [] === $this->categories) {
            $context->buildViolation('booking_rules.validation.categories')->atPath('categories')->addViolation();
        }

        if ($this->isPeriod
            && (null === $this->startDate || null === $this->lastDate || $this->lastDate < $this->startDate
                || $this->startDate->format('Y') < '1900' || $this->lastDate->format('Y') > '9998')) {
            $context->buildViolation('booking_rules.validation.period')->atPath('lastDate')->addViolation();
        }
    }
}
