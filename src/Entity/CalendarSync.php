<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CalendarSyncRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CalendarSyncRepository::class)]
#[ORM\Table(name: 'calendar_sync')]
#[ORM\Index(name: 'uuid_idx', columns: ['uuid'])]
class CalendarSync
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;
    #[ORM\Column(type: 'boolean')]
    private bool $isPublic;
    /** @var Collection<int, ReservationStatus> */
    #[ORM\ManyToMany(targetEntity: ReservationStatus::class, inversedBy: 'calendarSyncs', cascade: ['persist'])]
    private Collection $reservationStatus;
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $lastExport = null;
    #[ORM\OneToOne(targetEntity: Appartment::class, inversedBy: 'calendarSync', cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false)]
    private ?Appartment $apartment = null;
    #[ORM\Column(type: 'boolean')]
    private bool $exportGuestName;
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $exportReservationTimes = false;
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $exportPastEntries = false;

    public function __construct()
    {
        $this->uuid = Uuid::v4();
        $this->reservationStatus = new ArrayCollection();
        $this->isPublic = false;
        $this->exportGuestName = false;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function setUuid(Uuid $uuid): self
    {
        $this->uuid = $uuid;

        return $this;
    }

    public function getIsPublic(): ?bool
    {
        return $this->isPublic;
    }

    public function setIsPublic(bool $isPublic): self
    {
        $this->isPublic = $isPublic;

        return $this;
    }

    /**
     * @return Collection<int, ReservationStatus>
     */
    public function getReservationStatus(): Collection
    {
        return $this->reservationStatus;
    }

    public function addReservationStatus(ReservationStatus $reservationStatus): self
    {
        if (!$this->reservationStatus->contains($reservationStatus)) {
            $this->reservationStatus[] = $reservationStatus;
            $reservationStatus->addCalendarSync($this);
        }

        return $this;
    }

    public function removeReservationStatus(ReservationStatus $reservationStatus): self
    {
        $this->reservationStatus->removeElement($reservationStatus);

        return $this;
    }

    public function getLastExport(): ?\DateTimeInterface
    {
        return $this->lastExport;
    }

    public function setLastExport(?\DateTimeInterface $lastExport): self
    {
        $this->lastExport = $lastExport;

        return $this;
    }

    public function getApartment(): ?Appartment
    {
        return $this->apartment;
    }

    public function setApartment(Appartment $apartment): self
    {
        $this->apartment = $apartment;

        return $this;
    }

    public function getExportGuestName(): ?bool
    {
        return $this->exportGuestName;
    }

    public function setExportGuestName(bool $exportGuestName): self
    {
        $this->exportGuestName = $exportGuestName;

        return $this;
    }

    public function getExportReservationTimes(): bool
    {
        return $this->exportReservationTimes;
    }

    public function setExportReservationTimes(bool $exportReservationTimes): self
    {
        $this->exportReservationTimes = $exportReservationTimes;

        return $this;
    }

    public function getExportPastEntries(): bool
    {
        return $this->exportPastEntries;
    }

    public function setExportPastEntries(bool $exportPastEntries): self
    {
        $this->exportPastEntries = $exportPastEntries;

        return $this;
    }
}
