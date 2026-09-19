<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\BookingRestrictionType;
use App\Repository\BookingRestrictionRuleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One booking restriction for selected room categories and weekdays, independent of the
 * sales channel. A rule carrying a date range is a special period and replaces the
 * unlimited minimum-stay rules of its type; without dates it always applies.
 */
#[ORM\Entity(repositoryClass: BookingRestrictionRuleRepository::class)]
#[ORM\Table(name: 'booking_restriction_rule')]
class BookingRestrictionRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    /** Doctrine hydrates the generated identifier, including on proxy subclasses. */
    protected ?int $id = null;

    #[ORM\Column(length: 24, enumType: BookingRestrictionType::class)]
    private BookingRestrictionType $type = BookingRestrictionType::MIN_STAY_ARRIVAL;

    /** Null for the two closure types, which restrict a day rather than a duration. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $minNights = 1;

    /** @var list<int> ISO weekdays; Sunday is 7. */
    #[ORM\Column(type: Types::JSON)]
    private array $weekdays = [1, 2, 3, 4, 5, 6, 7];

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\Column]
    private bool $enabled = true;

    // An emptied category association must never silently broaden a rule to all rooms.
    #[ORM\Column]
    private bool $allCategories = true;

    /** @var Collection<int, RoomCategory> */
    #[ORM\ManyToMany(targetEntity: RoomCategory::class)]
    #[ORM\JoinTable(name: 'booking_restriction_rule_category')]
    #[ORM\JoinColumn(name: 'rule_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'category_id', onDelete: 'CASCADE')]
    private Collection $categories;

    public function __construct()
    {
        $this->categories = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): BookingRestrictionType
    {
        return $this->type;
    }

    /** Switching to a closure type drops the night count, which has no meaning there. */
    public function setType(BookingRestrictionType $type): void
    {
        $this->type = $type;

        if (!$type->needsMinNights()) {
            $this->minNights = null;
        }
    }

    public function getMinNights(): ?int
    {
        return $this->minNights;
    }

    /**
     * A minimum of one explicitly releases this restriction; it never means inheritance.
     * Null is only valid for the closure types.
     */
    public function setMinNights(?int $minNights): void
    {
        if (!$this->type->needsMinNights()) {
            $this->minNights = null;

            return;
        }

        if (null === $minNights || $minNights < 1 || $minNights > 365) {
            throw new \InvalidArgumentException('Minimum stay must be between 1 and 365 nights.');
        }

        $this->minNights = $minNights;
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

    /** Both dates are null for unlimited rules; otherwise the end is exclusive. */
    public function setPeriod(?\DateTimeImmutable $start, ?\DateTimeImmutable $end): void
    {
        if ((null === $start) !== (null === $end) || (null !== $start && $start >= $end)) {
            throw new \InvalidArgumentException('Rule dates must form a non-empty half-open interval.');
        }
        $this->startDate = $start;
        $this->endDate = $end;
    }

    /**
     * A dated rule is a special period. The distinction is derived rather than stored, so
     * the operator never has to pick a precedence level by hand.
     */
    public function isPeriod(): bool
    {
        return null !== $this->startDate;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function isAllCategories(): bool
    {
        return $this->allCategories;
    }

    public function setAllCategories(bool $allCategories): void
    {
        $this->allCategories = $allCategories;
    }

    /** @return Collection<int, RoomCategory> */
    public function getCategories(): Collection
    {
        return $this->categories;
    }

    /**
     * Replaces the category selection without creating independent copies of this rule.
     *
     * @param list<RoomCategory> $categories
     */
    public function setCategories(array $categories): void
    {
        $this->categories->clear();
        foreach ($categories as $category) {
            if (!$this->categories->contains($category)) {
                $this->categories->add($category);
            }
        }
    }

    public function appliesTo(RoomCategory $category): bool
    {
        if (!$this->enabled) {
            return false;
        }

        return $this->allCategories || $this->categories->exists(
            static fn (int $key, RoomCategory $selected): bool => $selected === $category || (null !== $selected->getId() && $selected->getId() === $category->getId())
        );
    }

    /** The day identifies an arrival, a departure or the start of a night; the period end is excluded. */
    public function coversDate(\DateTimeImmutable $date): bool
    {
        return in_array((int) $date->format('N'), $this->weekdays, true)
            && (null === $this->startDate || $date >= $this->startDate)
            && (null === $this->endDate || $date < $this->endDate);
    }
}
