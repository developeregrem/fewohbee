<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\HousekeepingStatus;
use App\Repository\RoomDayStatusRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Stores housekeeping-specific status data for a room on a given date.
 */
#[ORM\Entity(repositoryClass: RoomDayStatusRepository::class)]
#[ORM\Table(name: 'room_day_statuses')]
#[ORM\UniqueConstraint(name: 'uniq_room_day', columns: ['appartment_id', 'date'])]
class RoomDayStatus
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Appartment::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Appartment $appartment;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: 'string', enumType: HousekeepingStatus::class)]
    private HousekeepingStatus $hkStatus = HousekeepingStatus::OPEN;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $assignedTo = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $updatedBy = null;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * Return the primary database identifier.
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Return the apartment this status belongs to.
     */
    public function getAppartment(): Appartment
    {
        return $this->appartment;
    }

    /**
     * Assign the apartment this status belongs to.
     */
    public function setAppartment(Appartment $appartment): self
    {
        $this->appartment = $appartment;

        return $this;
    }

    /**
     * Return the date this status entry represents.
     */
    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    /**
     * Set the date this status entry represents.
     */
    public function setDate(\DateTimeImmutable $date): self
    {
        $this->date = $date;

        return $this;
    }

    /**
     * Return the current housekeeping status.
     */
    public function getHkStatus(): HousekeepingStatus
    {
        return $this->hkStatus;
    }

    /**
     * Set the housekeeping status for the day.
     */
    public function setHkStatus(HousekeepingStatus $hkStatus): self
    {
        $this->hkStatus = $hkStatus;

        return $this;
    }

    /**
     * Return the assigned user, if any.
     */
    public function getAssignedTo(): ?User
    {
        return $this->assignedTo;
    }

    /**
     * Assign a user to this housekeeping task.
     */
    public function setAssignedTo(?User $assignedTo): self
    {
        $this->assignedTo = $assignedTo;

        return $this;
    }

    /**
     * Return the housekeeping note.
     */
    public function getNote(): ?string
    {
        return $this->note;
    }

    /**
     * Set the housekeeping note.
     */
    public function setNote(?string $note): self
    {
        $this->note = $note;

        return $this;
    }

    /**
     * Return the last update timestamp.
     */
    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Update the timestamp for this entry.
     */
    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    /**
     * Return the user who last updated this entry.
     */
    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    /**
     * Set the user who last updated this entry.
     */
    public function setUpdatedBy(?User $updatedBy): self
    {
        $this->updatedBy = $updatedBy;

        return $this;
    }
}
