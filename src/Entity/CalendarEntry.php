<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CalendarEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single dated entry within a Calendar (waste collection day, vacation
 * period, event, ...). Generalizes what used to be WasteCollectionDate.
 */
#[ORM\Entity(repositoryClass: CalendarEntryRepository::class)]
#[ORM\Table(name: 'calendar_entry')]
#[ORM\UniqueConstraint(name: 'uniq_calendar_entry_ics_uid', columns: ['ics_uid'])]
#[ORM\Index(name: 'idx_calendar_entry_date', columns: ['date'])]
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
    private string $title;

    /**
     * Stable identifier derived from the ICS UID (plus date, for expanded
     * recurring events), or "manual-<random>" for manually created entries.
     * Used to upsert on re-sync without duplicating rows.
     */
    #[ORM\Column(name: 'ics_uid', type: Types::STRING, length: 255)]
    private string $icsUid;

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

    public function getIcsUid(): string
    {
        return $this->icsUid;
    }

    public function setIcsUid(string $icsUid): self
    {
        $this->icsUid = $icsUid;

        return $this;
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
