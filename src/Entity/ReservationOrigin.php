<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

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
    #[ORM\Column(type: 'string', length: 7, nullable: true)]
    #[Assert\Regex('/^#[0-9a-f]{6}$/i')]
    private $color;

    /**
     * The portal's commission for a booking through this origin, as a
     * percentage of the gross total. Together with the payment fee it makes up
     * what the guest carries over the direct price; kept apart so it mirrors
     * the two deductions booked for it. Null when none applies (direct booking).
     */
    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    private ?string $commissionPercent = null;

    /** The portal's payment fee, as a percentage of the gross total; see commission. */
    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    private ?string $paymentFeePercent = null;

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

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): self
    {
        $this->color = $color;

        return $this;
    }

    /** Portal commission as a percentage of the gross total, null when none applies. */
    public function getCommissionPercent(): ?string
    {
        return $this->commissionPercent;
    }

    public function setCommissionPercent(?string $commissionPercent): self
    {
        $this->commissionPercent = $commissionPercent;

        return $this;
    }

    /** Portal payment fee as a percentage of the gross total, null when none applies. */
    public function getPaymentFeePercent(): ?string
    {
        return $this->paymentFeePercent;
    }

    public function setPaymentFeePercent(?string $paymentFeePercent): self
    {
        $this->paymentFeePercent = $paymentFeePercent;

        return $this;
    }

    /**
     * Add prices.
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
     * @return ReservationOrigin
     */
    public function addReservation(Reservation $reservations)
    {
        $this->reservations[] = $reservations;

        return $this;
    }

    /**
     * Remove reservations.
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
