<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ReservationStatusRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ReservationStatusRepository::class)]
class ReservationStatus
{
    public const CODE_CANCELED_NOSHOW = 'canceled_noshow';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;
    #[ORM\Column(type: 'string', length: 100)]
    #[Assert\NotBlank]
    private $name;
    #[ORM\Column(type: 'string', length: 7)]
    #[Assert\Regex('/^#[0-9a-f]{6}$/i')]
    private $color;
    #[ORM\OneToMany(targetEntity: Reservation::class, mappedBy: 'reservationStatus')]
    private $reservations;
    #[ORM\Column(type: 'string', length: 7)]
    private $contrastColor;
    #[ORM\Column(type: 'string', length: 50, unique: true, nullable: true)]
    private $code;
    #[ORM\Column(type: 'boolean')]
    private $isBlocking = true;
    #[ORM\ManyToMany(targetEntity: CalendarSync::class, mappedBy: 'reservationStatus')]
    private $calendarSyncs;

    public function __construct()
    {
        $this->reservations = new ArrayCollection();
        $this->calendarSyncs = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(string $color): self
    {
        $this->color = $color;

        return $this;
    }

    /**
     * @return Collection|Reservation[]
     */
    public function getReservations(): Collection
    {
        return $this->reservations;
    }

    public function addReservation(Reservation $reservation): self
    {
        if (!$this->reservations->contains($reservation)) {
            $this->reservations[] = $reservation;
            $reservation->setReservationStatus($this);
        }

        return $this;
    }

    public function removeReservation(Reservation $reservation): self
    {
        if ($this->reservations->removeElement($reservation)) {
            // set the owning side to null (unless already changed)
            if ($reservation->getReservationStatus() === $this) {
                $reservation->setReservationStatus(null);
            }
        }

        return $this;
    }

    public function getContrastColor(): ?string
    {
        return $this->contrastColor;
    }

    public function setContrastColor(string $contrastColor): self
    {
        $this->contrastColor = $contrastColor;

        return $this;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): self
    {
        $this->code = $code;

        return $this;
    }

    public function isBlocking(): bool
    {
        return (bool) $this->isBlocking;
    }

    public function setIsBlocking(bool $isBlocking): self
    {
        $this->isBlocking = $isBlocking;

        return $this;
    }

    public function isSystem(): bool
    {
        return null !== $this->code && '' !== $this->code;
    }

    /**
     * @return Collection|CalendarSync[]
     */
    public function getCalendarSyncs(): Collection
    {
        return $this->calendarSyncs;
    }

    public function addCalendarSync(CalendarSync $calendarSync): self
    {
        if (!$this->calendarSyncs->contains($calendarSync)) {
            $this->calendarSyncs[] = $calendarSync;
            $calendarSync->addReservationStatus($this);
        }

        return $this;
    }

    public function removeCalendarSync(CalendarSync $calendarSync): self
    {
        if ($this->calendarSyncs->removeElement($calendarSync)) {
            $calendarSync->removeReservationStatus($this);
        }

        return $this;
    }
}
