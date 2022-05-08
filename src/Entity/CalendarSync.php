<?php

namespace App\Entity;

use App\Repository\CalendarSyncRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=CalendarSyncRepository::class)
 * @ORM\Table(name="calendar_sync", indexes={@ORM\Index(name="uuid_idx", columns={"uuid"})})
 */
class CalendarSync
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="uuid", unique=true)
     */
    private $uuid;

    /**
     * @ORM\Column(type="boolean")
     */
    private $isPublic;

    /**
     * @ORM\ManyToMany(targetEntity=ReservationStatus::class, inversedBy="calendarSyncs", cascade={"persist"})
     */
    private $reservationStatus;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $lastExport;

    /**
     * @ORM\OneToOne(targetEntity=Appartment::class, inversedBy="calendarSync", cascade={"persist"})
     * @ORM\JoinColumn(nullable=false)
     */
    private $apartment;

    /**
     * @ORM\Column(type="boolean")
     */
    private $exportGuestName;

    public function __construct()
    {
        $this->reservationStatus = new ArrayCollection();
        $this->isPublic = false;
        $this->exportGuestName = false;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid()
    {
        return $this->uuid;
    }

    public function setUuid($uuid): self
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
     * @return Collection|ReservationStatus[]
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
}
