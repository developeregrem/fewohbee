<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: 'App\Repository\AppartmentRepository')]
#[ORM\Table(name: 'appartments')]
class Appartment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;
    #[ORM\Column(type: 'string', length: 10)]
    private $number;
    #[ORM\Column(type: 'smallint')]
    private $beds_max;
    #[ORM\Column(type: 'string', length: 255)]
    private $description;
    #[ORM\ManyToOne(targetEntity: 'Subsidiary', inversedBy: 'appartments')]
    private $object;
    #[ORM\OneToMany(targetEntity: 'Reservation', mappedBy: 'appartment')]
    private $reservations;
    #[ORM\ManyToOne(targetEntity: 'App\Entity\RoomCategory', inversedBy: 'apartments')]
    #[ORM\JoinColumn(nullable: true)]
    private $roomCategory;
    #[ORM\OneToOne(targetEntity: CalendarSync::class, mappedBy: 'apartment', cascade: ['persist', 'remove'])]
    private $calendarSync;

    public function __construct()
    {
        $this->reservations = new ArrayCollection();
    }

    public function getId()
    {
        return $this->id;
    }

    public function getNumber()
    {
        return $this->number;
    }

    public function getBedsMax()
    {
        return $this->beds_max;
    }

    public function getDescription()
    {
        return $this->description;
    }

    public function getReservations()
    {
        return $this->reservations;
    }

    /**
     * Set id.
     *
     * @param int $id
     *
     * @return Appartment
     */
    public function setId($id)
    {
        $this->id = $id;

        return $this;
    }

    public function setNumber($number): void
    {
        $this->number = $number;
    }

    public function setBedsMax($beds_max): void
    {
        $this->beds_max = $beds_max;
    }

    public function setDescription($description): void
    {
        $this->description = $description;
    }

    public function getObject()
    {
        return $this->object;
    }

    public function setObject($object): void
    {
        $this->object = $object;
    }

    public function setReservations($reservations): void
    {
        $this->reservations = $reservations;
    }

    public function addReservation(Reservation $reservation)
    {
        $this->reservations[] = $reservation;

        return $this;
    }

    public function removeReservation(Reservation $reservation): void
    {
        $this->reservations->removeElement($reservation);
    }

    public function getRoomCategory(): ?RoomCategory
    {
        return $this->roomCategory;
    }

    public function setRoomCategory(?RoomCategory $roomCategory): self
    {
        $this->roomCategory = $roomCategory;

        return $this;
    }

    public function getCalendarSync(): ?CalendarSync
    {
        return $this->calendarSync;
    }

    public function setCalendarSync(CalendarSync $calendarSync): self
    {
        // set the owning side of the relation if necessary
        if ($calendarSync->getApartment() !== $this) {
            $calendarSync->setApartment($this);
        }

        $this->calendarSync = $calendarSync;

        return $this;
    }
}
