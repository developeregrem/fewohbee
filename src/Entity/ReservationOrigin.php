<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'reservation_origins')]
class ReservationOrigin
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;
    #[ORM\Column(type: 'string', length: 100)]
    private $name;
    #[ORM\ManyToMany(targetEntity: 'Price', mappedBy: 'reservationOrigins')]
    private $prices;
    #[ORM\OneToMany(targetEntity: 'Reservation', mappedBy: 'reservationOrigin')]
    private $reservations;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->prices = new ArrayCollection();
        $this->reservations = new ArrayCollection();
    }

    /**
     * Set id.
     *
     * @param int $id
     *
     * @return ReservationOrigin
     */
    public function setId($id)
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Get id.
     *
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set name.
     *
     * @param string $name
     *
     * @return ReservationOrigin
     */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Get name.
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Add prices.
     *
     * @param \App\Entity\Price $prices
     *
     * @return ReservationOrigin
     */
    public function addPrice(Price $prices)
    {
        $this->prices[] = $prices;

        return $this;
    }

    /**
     * Remove prices.
     *
     * @param \App\Entity\Price $prices
     */
    public function removePrice(Price $prices): void
    {
        $this->prices->removeElement($prices);
    }

    /**
     * Get prices.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getPrices()
    {
        return $this->prices;
    }

    /**
     * Add reservations.
     *
     * @param \App\Entity\Reservation $reservations
     *
     * @return ReservationOrigin
     */
    public function addReservation(Reservation $reservations)
    {
        $this->reservations[] = $reservations;

        return $this;
    }

    /**
     * Remove reservations.
     *
     * @param \App\Entity\Reservation $reservations
     */
    public function removeReservation(Reservation $reservations): void
    {
        $this->reservations->removeElement($reservations);
    }

    /**
     * Get reservations.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getReservations()
    {
        return $this->reservations;
    }
}
