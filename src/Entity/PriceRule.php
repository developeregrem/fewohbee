<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\PriceRuleCondition;
use App\Repository\PriceRuleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Raises or lowers the room price by a percentage on the nights its condition matches, for one,
 * several or all subsidiaries and room categories. All rules matching a night are added up, so
 * their order never matters. Extras and flat prices are never changed.
 */
#[ORM\Entity(repositoryClass: PriceRuleRepository::class)]
#[ORM\Table(name: 'price_rules')]
class PriceRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    /** Doctrine hydrates the generated identifier, including on proxy subclasses. */
    protected ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name = '';

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column(name: 'rule_condition', length: 20, enumType: PriceRuleCondition::class)]
    private PriceRuleCondition $condition = PriceRuleCondition::ALWAYS;

    /** Signed: negative lowers the price. */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    private string $percent = '0.00';

    /** Days before the night; only for the conditions that use it. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $days = null;

    /** Booked share of the rooms in percent; only for the occupancy conditions. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $occupancy = null;

    /**
     * Occupancy rules judge each subsidiary on its own by default. Set, they count the rooms of
     * all subsidiaries the rule covers together - for houses split into several subsidiaries,
     * e.g. one per floor.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $occupancyAcrossSubsidiaries = false;

    /** @var list<int> ISO weekdays of the night; Sunday is 7. */
    #[ORM\Column(type: Types::JSON)]
    private array $weekdays = [1, 2, 3, 4, 5, 6, 7];

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startDate = null;

    /** Exclusive: the first night the rule no longer covers. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endDate = null;

    // An emptied association must never silently broaden a rule to all subsidiaries or rooms.
    #[ORM\Column]
    private bool $allSubsidiaries = true;

    /** @var Collection<int, Subsidiary> */
    #[ORM\ManyToMany(targetEntity: Subsidiary::class)]
    #[ORM\JoinTable(name: 'price_rule_subsidiary')]
    #[ORM\JoinColumn(name: 'rule_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'subsidiary_id', onDelete: 'CASCADE')]
    private Collection $subsidiaries;

    #[ORM\Column]
    private bool $allCategories = true;

    /** @var Collection<int, RoomCategory> */
    #[ORM\ManyToMany(targetEntity: RoomCategory::class)]
    #[ORM\JoinTable(name: 'price_rule_category')]
    #[ORM\JoinColumn(name: 'rule_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'category_id', onDelete: 'CASCADE')]
    private Collection $categories;

    public function __construct()
    {
        $this->subsidiaries = new ArrayCollection();
        $this->categories = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = mb_substr(trim($name), 0, 100);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function getCondition(): PriceRuleCondition
    {
        return $this->condition;
    }

    /**
     * Sets the condition with its thresholds; values the condition does not use are dropped,
     * so a rule never carries a stale threshold from an earlier edit.
     */
    public function setCondition(PriceRuleCondition $condition, ?int $days = null, ?int $occupancy = null): void
    {
        if ($condition->usesDays() && (null === $days || $days < 0 || $days > 730)) {
            throw new \InvalidArgumentException('The number of days must be between 0 and 730.');
        }
        if ($condition->usesOccupancy() && (null === $occupancy || $occupancy < 0 || $occupancy > 100)) {
            throw new \InvalidArgumentException('The occupancy must be between 0 and 100 percent.');
        }
        $this->condition = $condition;
        $this->days = $condition->usesDays() ? $days : null;
        $this->occupancy = $condition->usesOccupancy() ? $occupancy : null;
        $this->occupancyAcrossSubsidiaries = $this->occupancyAcrossSubsidiaries && $condition->usesOccupancy();
    }

    public function getDays(): ?int
    {
        return $this->days;
    }

    public function getOccupancy(): ?int
    {
        return $this->occupancy;
    }

    public function isOccupancyAcrossSubsidiaries(): bool
    {
        return $this->occupancyAcrossSubsidiaries;
    }

    /** Only kept for the occupancy conditions; meaningless for the others. */
    public function setOccupancyAcrossSubsidiaries(bool $across): void
    {
        $this->occupancyAcrossSubsidiaries = $across && $this->condition->usesOccupancy();
    }

    public function getPercent(): float
    {
        return (float) $this->percent;
    }

    public function setPercent(float $percent): void
    {
        if ($percent <= -100.0 || $percent > 500.0 || 0.0 === round($percent, 2)) {
            throw new \InvalidArgumentException('The change must be above -100 and at most 500 percent, and not zero.');
        }
        $this->percent = number_format($percent, 2, '.', '');
    }

    /** @return list<int> */
    public function getWeekdays(): array
    {
        return $this->weekdays;
    }

    /** @param list<int> $weekdays ISO weekdays, with at least one selected day. */
    public function setWeekdays(array $weekdays): void
    {
        if ([] === $weekdays || array_any($weekdays, static fn (int $day): bool => $day < 1 || $day > 7)) {
            throw new \InvalidArgumentException('Select valid ISO weekdays.');
        }
        $this->weekdays = array_values(array_unique($weekdays));
        sort($this->weekdays);
    }

    public function getStartDate(): ?\DateTimeImmutable
    {
        return $this->startDate;
    }

    public function getEndDate(): ?\DateTimeImmutable
    {
        return $this->endDate;
    }

    /** Both dates are null for a rule without date range; otherwise the end is exclusive. */
    public function setPeriod(?\DateTimeImmutable $start, ?\DateTimeImmutable $end): void
    {
        if ((null === $start) !== (null === $end) || (null !== $start && $start >= $end)) {
            throw new \InvalidArgumentException('Rule dates must form a non-empty half-open interval.');
        }
        $this->startDate = $start;
        $this->endDate = $end;
    }

    public function isAllSubsidiaries(): bool
    {
        return $this->allSubsidiaries;
    }

    /** @return Collection<int, Subsidiary> */
    public function getSubsidiaries(): Collection
    {
        return $this->subsidiaries;
    }

    /** @param list<Subsidiary> $subsidiaries ignored when the rule applies to all */
    public function setSubsidiaries(bool $all, array $subsidiaries): void
    {
        $this->allSubsidiaries = $all;
        $this->subsidiaries->clear();
        foreach ($all ? [] : $subsidiaries as $subsidiary) {
            if (!$this->subsidiaries->contains($subsidiary)) {
                $this->subsidiaries->add($subsidiary);
            }
        }
    }

    public function isAllCategories(): bool
    {
        return $this->allCategories;
    }

    /** @return Collection<int, RoomCategory> */
    public function getCategories(): Collection
    {
        return $this->categories;
    }

    /** @param list<RoomCategory> $categories ignored when the rule applies to all */
    public function setCategories(bool $all, array $categories): void
    {
        $this->allCategories = $all;
        $this->categories->clear();
        foreach ($all ? [] : $categories as $category) {
            if (!$this->categories->contains($category)) {
                $this->categories->add($category);
            }
        }
    }

    /** Whether the rule is meant for rooms of this category in this subsidiary. */
    public function appliesTo(?Subsidiary $subsidiary, RoomCategory $category): bool
    {
        if (!$this->allSubsidiaries && (null === $subsidiary || !$this->subsidiaries->contains($subsidiary))) {
            return false;
        }

        return $this->allCategories || $this->categories->contains($category);
    }

    /** Whether the night lies in the rule's date range and on one of its weekdays. */
    public function coversNight(\DateTimeImmutable $night): bool
    {
        if (null !== $this->startDate && ($night < $this->startDate || $night >= $this->endDate)) {
            return false;
        }

        return in_array((int) $night->format('N'), $this->weekdays, true);
    }
}
