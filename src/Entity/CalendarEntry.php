<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CalendarEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A single dated entry within a Calendar (waste collection day, vacation
 * period, event, ...). Generalizes what used to be WasteCollectionDate.
 */
#[ORM\Entity(repositoryClass: CalendarEntryRepository::class)]
#[ORM\Table(name: 'calendar_entry')]
#[ORM\Index(name: 'idx_calendar_entry_date', columns: ['date'])]
#[ORM\Index(name: 'idx_calendar_entry_source', columns: ['calendar_id', 'source_uid'])]
class CalendarEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Calendar::class, inversedBy: 'entries')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Calendar $calendar;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    /** Free-text label, e.g. "Restmüll", "Sommerferien", "Wartung Heizung". */
    #[ORM\Column(type: Types::STRING, length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $title;

    /**
     * Identifies the source event this entry came from - the ICS UID,
     * prefixed with the calendar id so the same UID in two feeds can't
     * collide. Null for manually created entries.
     *
     * Deliberately carries no date: a multi-day or recurring source event
     * owns several entries under one sourceUid, and re-syncing reconciles
     * that whole set against the source (see CalendarEntrySyncService), so
     * moving an occurrence updates its entry instead of orphaning it and
     * inserting a second one.
     */
    #[ORM\Column(name: 'source_uid', type: Types::STRING, length: 255, nullable: true)]
    private ?string $sourceUid = null;

    /**
     * Set once staff acknowledges the day-before reminder for this date -
     * only relevant when the owning Calendar has requiresConfirmation set.
     */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $confirmedAt = null;

    /** Who acknowledged the reminder. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $confirmedBy = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCalendar(): Calendar
    {
        return $this->calendar;
    }

    public function setCalendar(Calendar $calendar): self
    {
        $this->calendar = $calendar;

        return $this;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): self
    {
        $this->date = $date;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getSourceUid(): ?string
    {
        return $this->sourceUid;
    }

    public function setSourceUid(?string $sourceUid): self
    {
        $this->sourceUid = $sourceUid;

        return $this;
    }

    /**
     * Whether this entry was created through the manual "+ new entry" form
     * rather than an ICS sync - see the sourceUid doc comment above. The
     * calendar's hasIcsSource() alone isn't enough to tell: a calendar with
     * a configured URL can still have manually added entries alongside the
     * synced ones.
     */
    public function isManuallyCreated(): bool
    {
        return null === $this->sourceUid;
    }

    public function getConfirmedAt(): ?\DateTime
    {
        return $this->confirmedAt;
    }

    public function setConfirmedAt(?\DateTime $confirmedAt): self
    {
        $this->confirmedAt = $confirmedAt;

        return $this;
    }

    public function getConfirmedBy(): ?User
    {
        return $this->confirmedBy;
    }

    public function setConfirmedBy(?User $confirmedBy): self
    {
        $this->confirmedBy = $confirmedBy;

        return $this;
    }

    public function isConfirmed(): bool
    {
        return null !== $this->confirmedAt;
    }

    public function needsConfirmation(): bool
    {
        return $this->calendar->isRequiresConfirmation() && !$this->isConfirmed();
    }
}
