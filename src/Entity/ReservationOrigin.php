<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\PaymentCollection;
use App\Repository\ReservationOriginRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ReservationOriginRepository::class)]
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
     * The portal's commission for a booking through this origin, in percent -
     * of what the invoice marks as commissionable rather than of its gross
     * total, see OriginFeeCalculator. Together with the payment fee it makes up
     * what the guest carries over the direct price; kept apart so it mirrors
     * the two deductions booked for it. Null when none applies (direct booking).
     */
    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    private ?string $commissionPercent = null;

    /** The portal's payment fee in percent, taken of what the portal processed; see commission. */
    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    private ?string $paymentFeePercent = null;

    /**
     * Who collects the guest's payment for a booking through this origin. The
     * default for its reservations, which pin it as they are booked - a portal
     * that changes how it settles must not rewrite what happened to older
     * bookings.
     *
     * Defaults to the house, which is what a direct booking does and the
     * harmless answer for a portal that only passes bookings on: no payment
     * handled, no payment fee charged.
     */
    #[ORM\Column(name: 'payment_collection', type: 'string', length: 16, enumType: PaymentCollection::class, options: ['default' => 'property'])]
    private PaymentCollection $paymentCollection = PaymentCollection::PROPERTY;

    /**
     * Who collects the tourist tax, asked separately because portals differ and
     * because the same portal can be set up either way: entered on their side as
     * a fee payable on arrival, the house takes it; entered as a separate local
     * tax on a portal that collects payments, the portal does.
     *
     * It decides nothing about commission - a separately billed tourist tax is
     * taken to carry none either way (see InvoicePosition::$commissionable) -
     * only whether the portal's payment fee is charged on it.
     */
    #[ORM\Column(name: 'tourist_tax_collection', type: 'string', length: 16, enumType: PaymentCollection::class, options: ['default' => 'property'])]
    private PaymentCollection $touristTaxCollection = PaymentCollection::PROPERTY;

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

    /** Portal commission in percent, null when none applies. */
    public function getCommissionPercent(): ?string
    {
        return $this->commissionPercent;
    }

    public function setCommissionPercent(?string $commissionPercent): self
    {
        $this->commissionPercent = $commissionPercent;

        return $this;
    }

    /** Portal payment fee in percent, null when none applies. */
    public function getPaymentFeePercent(): ?string
    {
        return $this->paymentFeePercent;
    }

    public function setPaymentFeePercent(?string $paymentFeePercent): self
    {
        $this->paymentFeePercent = $paymentFeePercent;

        return $this;
    }

    public function hasOtaFees(): bool
    {
        return null !== $this->commissionPercent || null !== $this->paymentFeePercent;
    }

    /** Who collects the guest's payment for a booking through this origin. */
    public function getPaymentCollection(): PaymentCollection
    {
        return $this->paymentCollection;
    }

    public function setPaymentCollection(PaymentCollection $paymentCollection): self
    {
        $this->paymentCollection = $paymentCollection;

        return $this;
    }

    /** Who collects the tourist tax, which a portal can settle differently from the stay. */
    public function getTouristTaxCollection(): PaymentCollection
    {
        return $this->touristTaxCollection;
    }

    public function setTouristTaxCollection(PaymentCollection $touristTaxCollection): self
    {
        $this->touristTaxCollection = $touristTaxCollection;

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
