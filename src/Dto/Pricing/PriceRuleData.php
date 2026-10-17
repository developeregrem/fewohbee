<?php

declare(strict_types=1);

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Dto\Pricing;

use App\Entity\Enum\PriceRuleCondition;
use App\Entity\PriceRule;
use App\Entity\RoomCategory;
use App\Entity\Subsidiary;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Form input for a price rule, kept apart from the entity so an invalid submission or a preview
 * never reaches the database. The percentage is entered as an amount plus a direction, so no
 * one has to type a minus sign; the dates are the first and the last night, both inclusive.
 */
final class PriceRuleData
{
    /** Starting points offered when adding a rule; the key is the template name. */
    public const TEMPLATES = ['weekend', 'season', 'last_minute', 'early_bird', 'occupancy_high', 'occupancy_low'];

    #[Assert\NotBlank(message: 'price_rules.validation.name')]
    #[Assert\Length(max: 100)]
    public string $name = '';

    public PriceRuleCondition $condition = PriceRuleCondition::ALWAYS;

    public bool $raise = true;

    public ?float $amount = 10.0;

    public ?int $days = null;

    public ?int $occupancy = null;

    /** Count the occupancy of all subsidiaries of the rule together instead of each on its own. */
    public bool $occupancyAcrossSubsidiaries = false;

    /** @var list<int> ISO weekdays of the night; Sunday is 7. */
    public array $weekdays = [1, 2, 3, 4, 5, 6, 7];

    public ?\DateTimeImmutable $firstNight = null;

    public ?\DateTimeImmutable $lastNight = null;

    public bool $allSubsidiaries = true;

    /** @var list<Subsidiary> */
    public array $subsidiaries = [];

    public bool $allCategories = true;

    /** @var list<RoomCategory> */
    public array $categories = [];

    public bool $enabled = true;

    /** Prefilled data for one of the TEMPLATES; the name is translated by the caller. */
    public static function fromTemplate(string $template, string $name): self
    {
        $data = new self();
        $data->name = $name;
        match ($template) {
            'weekend' => $data->weekdays = [5, 6],
            'season' => $data->amount = 15.0,
            'last_minute' => [$data->condition, $data->days, $data->raise] = [PriceRuleCondition::LAST_MINUTE, 3, false],
            'early_bird' => [$data->condition, $data->days, $data->raise, $data->amount] = [PriceRuleCondition::EARLY_BIRD, 90, false, 5.0],
            'occupancy_high' => [$data->condition, $data->occupancy, $data->amount] = [PriceRuleCondition::OCCUPANCY_HIGH, 80, 15.0],
            'occupancy_low' => [$data->condition, $data->occupancy, $data->days, $data->raise] = [PriceRuleCondition::OCCUPANCY_LOW, 40, 14, false],
            default => null,
        };

        return $data;
    }

    public static function fromRule(PriceRule $rule): self
    {
        $data = new self();
        $data->name = $rule->getName();
        $data->condition = $rule->getCondition();
        $data->raise = $rule->getPercent() > 0;
        $data->amount = abs($rule->getPercent());
        $data->days = $rule->getDays();
        $data->occupancy = $rule->getOccupancy();
        $data->occupancyAcrossSubsidiaries = $rule->isOccupancyAcrossSubsidiaries();
        $data->weekdays = $rule->getWeekdays();
        $data->firstNight = $rule->getStartDate();
        $data->lastNight = $rule->getEndDate()?->modify('-1 day');
        $data->allSubsidiaries = $rule->isAllSubsidiaries();
        $data->subsidiaries = $rule->getSubsidiaries()->getValues();
        $data->allCategories = $rule->isAllCategories();
        $data->categories = $rule->getCategories()->getValues();
        $data->enabled = $rule->isEnabled();

        return $data;
    }

    public function percent(): float
    {
        return ($this->raise ? 1 : -1) * (float) $this->amount;
    }

    /** Fields the chosen condition does not use are not validated - they are dropped on save. */
    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if (null === $this->amount || $this->amount <= 0 || $this->amount > ($this->raise ? 500 : 99)) {
            $context->buildViolation('price_rules.validation.amount')->atPath('amount')->addViolation();
        }
        if ($this->condition->usesDays() && (null === $this->days || $this->days < 0 || $this->days > 730)) {
            $context->buildViolation('price_rules.validation.days')->atPath('days')->addViolation();
        }
        if ($this->condition->usesOccupancy() && (null === $this->occupancy || $this->occupancy < 0 || $this->occupancy > 100)) {
            $context->buildViolation('price_rules.validation.occupancy')->atPath('occupancy')->addViolation();
        }
        if ([] === $this->weekdays) {
            $context->buildViolation('price_rules.validation.weekdays')->atPath('weekdays')->addViolation();
        }
        if ((null === $this->firstNight) !== (null === $this->lastNight)
            || (null !== $this->firstNight && $this->lastNight < $this->firstNight)) {
            $context->buildViolation('price_rules.validation.period')->atPath('lastNight')->addViolation();
        }
        if (!$this->allSubsidiaries && [] === $this->subsidiaries) {
            $context->buildViolation('price_rules.validation.subsidiaries')->atPath('subsidiaries')->addViolation();
        }
        if (!$this->allCategories && [] === $this->categories) {
            $context->buildViolation('price_rules.validation.categories')->atPath('categories')->addViolation();
        }
    }
}
