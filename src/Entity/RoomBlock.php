<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\RoomBlockSource;
use App\Repository\RoomBlockRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Blocks a physical room for a period (out of order / own use); endDate is exclusive.
 */
#[ORM\Entity(repositoryClass: RoomBlockRepository::class)]
#[ORM\Table(name: 'room_blocks')]
#[ORM\Index(name: 'idx_room_blocks_room_dates', columns: ['appartment_id', 'start_date', 'end_date'])]
class RoomBlock
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: Appartment::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Appartment $appartment;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $startDate;

    /** Exclusive: the room is available again on this day. */
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $endDate;

    #[ORM\Column(type: 'string', length: 255)]
    private string $reason;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: 'string', length: 32, enumType: RoomBlockSource::class)]
    private RoomBlockSource $source = RoomBlockSource::MANUAL;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    public function __construct()
    {
        $this->uuid = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getAppartment(): Appartment
    {
        return $this->appartment;
    }

    public function setAppartment(Appartment $appartment): self
    {
        $this->appartment = $appartment;

        return $this;
    }

    public function getStartDate(): \DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(\DateTimeImmutable $startDate): self
    {
        $this->startDate = $startDate;

        return $this;
    }

    public function getEndDate(): \DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(\DateTimeImmutable $endDate): self
    {
        $this->endDate = $endDate;

        return $this;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function setReason(string $reason): self
    {
        $this->reason = $reason;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $this->note = $note;

        return $this;
    }

    public function getSource(): RoomBlockSource
    {
        return $this->source;
    }

    public function setSource(RoomBlockSource $source): self
    {
        $this->source = $source;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): self
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    /**
     * Number of blocked nights (endDate is exclusive).
     */
    public function getNights(): int
    {
        return (int) $this->startDate->diff($this->endDate)->format('%a');
    }
}
