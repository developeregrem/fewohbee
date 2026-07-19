<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CalendarRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A configured calendar (waste collection, vacations, events, ...) that
 * entries can be imported into via ICS, or created manually. Generalizes
 * what used to be a single hardcoded "waste calendar" in AppSettings.
 */
#[ORM\Entity(repositoryClass: CalendarRepository::class)]
#[ORM\Table(name: 'calendar')]
class Calendar
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $name;

    /** Hex color (e.g. #ffc107), used for the accent line in the year overview. */
    #[ORM\Column(type: Types::STRING, length: 20)]
    #[Assert\Length(max: 20)]
    private string $color = '#ffc107';

    /**
     * Whether entries in this calendar need the day-before "confirm"
     * reminder (e.g. waste collection: yes: put the bin out; vacations: no).
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $requiresConfirmation = false;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $icsUrl = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $lastSyncedAt = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $lastSyncCount = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $lastSyncNewCount = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $lastSyncUpdatedCount = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $lastSyncUnchangedCount = null;

    /** @var \Doctrine\Common\Collections\Collection<int, CalendarEntry> */
    #[ORM\OneToMany(mappedBy: 'calendar', targetEntity: CalendarEntry::class, orphanRemoval: true)]
    private \Doctrine\Common\Collections\Collection $entries;

    public function __construct()
    {
        $this->entries = new \Doctrine\Common\Collections\ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): self
    {
        $this->color = $color;

        return $this;
    }

    public function isRequiresConfirmation(): bool
    {
        return $this->requiresConfirmation;
    }

    public function setRequiresConfirmation(bool $requiresConfirmation): self
    {
        $this->requiresConfirmation = $requiresConfirmation;

        return $this;
    }

    public function getIcsUrl(): ?string
    {
        return $this->icsUrl;
    }

    public function setIcsUrl(?string $icsUrl): self
    {
        $this->icsUrl = '' === $icsUrl ? null : $icsUrl;

        return $this;
    }

    public function getLastSyncedAt(): ?\DateTime
    {
        return $this->lastSyncedAt;
    }

    public function setLastSyncedAt(?\DateTime $lastSyncedAt): self
    {
        $this->lastSyncedAt = $lastSyncedAt;

        return $this;
    }

    public function getLastSyncCount(): ?int
    {
        return $this->lastSyncCount;
    }

    public function setLastSyncCount(?int $lastSyncCount): self
    {
        $this->lastSyncCount = $lastSyncCount;

        return $this;
    }

    public function getLastSyncNewCount(): ?int
    {
        return $this->lastSyncNewCount;
    }

    public function setLastSyncNewCount(?int $lastSyncNewCount): self
    {
        $this->lastSyncNewCount = $lastSyncNewCount;

        return $this;
    }

    public function getLastSyncUpdatedCount(): ?int
    {
        return $this->lastSyncUpdatedCount;
    }

    public function setLastSyncUpdatedCount(?int $lastSyncUpdatedCount): self
    {
        $this->lastSyncUpdatedCount = $lastSyncUpdatedCount;

        return $this;
    }

    public function getLastSyncUnchangedCount(): ?int
    {
        return $this->lastSyncUnchangedCount;
    }

    public function setLastSyncUnchangedCount(?int $lastSyncUnchangedCount): self
    {
        $this->lastSyncUnchangedCount = $lastSyncUnchangedCount;

        return $this;
    }

    public function hasIcsSource(): bool
    {
        return null !== $this->icsUrl;
    }
}
