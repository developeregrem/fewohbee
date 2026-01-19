<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CalendarSyncImportRepository;
use Doctrine\ORM\Mapping as ORM;

/** Store a remote iCal import configuration. */
#[ORM\Entity(repositoryClass: CalendarSyncImportRepository::class)]
#[ORM\Table(name: 'calendar_sync_import')]
#[ORM\Index(name: 'calendar_sync_import_apartment_idx', columns: ['apartment_id'])]
class CalendarSyncImport
{
    public const CONFLICT_SKIP = 'skip';
    public const CONFLICT_OVERWRITE = 'overwrite';
    public const CONFLICT_MARK = 'mark_conflict';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\Column(type: 'string', length: 100)]
    private string $name;

    #[ORM\Column(type: 'string', length: 2048)]
    private string $url;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'string', length: 50)]
    private string $conflictStrategy = self::CONFLICT_MARK;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $lastSyncAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastSyncError = null;

    #[ORM\ManyToOne(targetEntity: ReservationOrigin::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ReservationOrigin $reservationOrigin;

    #[ORM\ManyToOne(targetEntity: ReservationStatus::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ReservationStatus $reservationStatus;

    #[ORM\ManyToOne(targetEntity: Appartment::class, inversedBy: 'calendarSyncImports')]
    #[ORM\JoinColumn(nullable: false)]
    private Appartment $apartment;

    /** Create a default import configuration. */
    public function __construct()
    {
    }

    /** Return the unique import identifier. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** Return the display name for this import. */
    public function getName(): string
    {
        return $this->name;
    }

    /** Set the display name for this import. */
    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /** Return the source URL for the iCal feed. */
    public function getUrl(): string
    {
        return $this->url;
    }

    /** Set the source URL for the iCal feed. */
    public function setUrl(string $url): self
    {
        $this->url = $url;

        return $this;
    }

    /** Return whether the import is active. */
    public function isActive(): bool
    {
        return $this->isActive;
    }

    /** Set whether the import is active. */
    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        return $this;
    }

    /** Return the configured conflict strategy. */
    public function getConflictStrategy(): string
    {
        return $this->conflictStrategy;
    }

    /** Set the configured conflict strategy. */
    public function setConflictStrategy(string $conflictStrategy): self
    {
        $this->conflictStrategy = $conflictStrategy;

        return $this;
    }

    /** Return the last successful sync timestamp. */
    public function getLastSyncAt(): ?\DateTimeInterface
    {
        return $this->lastSyncAt;
    }

    /** Set the last successful sync timestamp. */
    public function setLastSyncAt(?\DateTimeInterface $lastSyncAt): self
    {
        $this->lastSyncAt = $lastSyncAt;

        return $this;
    }

    /** Return the last sync error message. */
    public function getLastSyncError(): ?string
    {
        return $this->lastSyncError;
    }

    /** Set the last sync error message. */
    public function setLastSyncError(?string $lastSyncError): self
    {
        $this->lastSyncError = $lastSyncError;

        return $this;
    }

    /** Return the reservation origin applied to imported entries. */
    public function getReservationOrigin(): ReservationOrigin
    {
        return $this->reservationOrigin;
    }

    /** Set the reservation origin applied to imported entries. */
    public function setReservationOrigin(ReservationOrigin $reservationOrigin): self
    {
        $this->reservationOrigin = $reservationOrigin;

        return $this;
    }

    /** Return the default reservation status for imported entries. */
    public function getReservationStatus(): ReservationStatus
    {
        return $this->reservationStatus;
    }

    /** Set the default reservation status for imported entries. */
    public function setReservationStatus(ReservationStatus $reservationStatus): self
    {
        $this->reservationStatus = $reservationStatus;

        return $this;
    }

    /** Return the linked apartment for this import. */
    public function getApartment(): Appartment
    {
        return $this->apartment;
    }

    /** Associate the import with an apartment. */
    public function setApartment(Appartment $apartment): self
    {
        $this->apartment = $apartment;

        return $this;
    }
}
