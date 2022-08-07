<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * @ORM\Entity(repositoryClass="App\Repository\ReservationRepository")
 * @ORM\Table(name="reservations", indexes={@ORM\Index(name="idx_uuid", columns={"uuid"})})
 **/
class Reservation
{
    /** @ORM\Id @ORM\Column(type="integer") @ORM\GeneratedValue * */
    private $id;

    /** @ORM\Column(name="start_date", type="date") * */
    private $startDate;

    /** @ORM\Column(name="end_date", type="date") * */
    private $endDate;

    /** @ORM\Column(type="smallint") * */
    private $persons;

    /** @ORM\Column(name="option_date", type="date", nullable=true) * */
    private $optionDate;

    /** @ORM\Column(type="text", nullable=true) * */
    private $remark;

    /** @ORM\Column(name="reservation_date", type="datetime") * */
    private $reservationDate;

    /**
     * @ORM\ManyToMany(targetEntity="Invoice", inversedBy="reservations")
     * @ORM\JoinTable(name="reservations_has_invoices")
     */
    private $invoices;

    /**
     * @ORM\ManyToOne(targetEntity="Appartment", inversedBy="reservations")
     */
    private $appartment;

    /**
     * @ORM\ManyToMany(targetEntity="Customer", inversedBy="reservations")
     * @ORM\JoinTable(name="reservations_has_customers")
     */
    private $customers;

    /**
     * @ORM\ManyToOne(targetEntity="Customer", inversedBy="bookedReservations")
     */
    private $booker;

    /**
     * @ORM\OneToMany(targetEntity="RegistrationBookEntry", mappedBy="reservation")
     */
    private $registrationBookEntries;

    /**
     * @ORM\ManyToOne(targetEntity="ReservationOrigin", inversedBy="reservations")
     */
    private $reservationOrigin;

    /**
     * @ORM\OneToMany(targetEntity="Correspondence", mappedBy="reservation", cascade={"remove"})
     */
    private $correspondences;

    /**
     * @ORM\ManyToMany(targetEntity=Price::class)
     */
    private $prices;

    /**
     * @ORM\ManyToOne(targetEntity=ReservationStatus::class, inversedBy="reservations")
     * @ORM\JoinColumn(nullable=false)
     */
    private $reservationStatus;

    /**
     * @ORM\Column(type="uuid", unique=true)
     */
    private $uuid;

    public function __construct()
    {
        $this->reservationDate = new \DateTime('now');
        $this->registrationBookEntries = new ArrayCollection();
        $this->customers = new ArrayCollection();
        $this->correspondences = new ArrayCollection();
        $this->invoices = new ArrayCollection();
        $this->prices = new ArrayCollection();
    }

    public function getId()
    {
        return $this->id;
    }

    public function getStartDate()
    {
        return $this->startDate;
    }

    public function getEndDate()
    {
        return $this->endDate;
    }

    public function getPersons()
    {
        return $this->persons;
    }

    public function getOptionDate()
    {
        return $this->optionDate;
    }

    public function getRemark()
    {
        return $this->remark;
    }

    public function getRemarkF()
    {
        return nl2br($this->remark);
    }

    public function getReservationDate()
    {
        return $this->reservationDate;
    }

    /**
     * @return Appartment
     */
    public function getAppartment()
    {
        return $this->appartment;
    }

    public function setId($id): void
    {
        $this->id = $id;
    }

    public function setStartDate($startDate): void
    {
        $this->startDate = $startDate;
    }

    public function setEndDate($endDate): void
    {
        $this->endDate = $endDate;
    }

    public function setPersons($persons): void
    {
        $this->persons = $persons;
    }

    public function setOptionDate($optionDate): void
    {
        $this->optionDate = $optionDate;
    }

    public function setRemark($remark): void
    {
        $this->remark = $remark;
    }

    public function setReservationDate($reservationDate): void
    {
        $this->reservationDate = $reservationDate;
    }

    public function setAppartment($appartment): void
    {
        $this->appartment = $appartment;
    }

    public function setCustomers($customers): void
    {
        $this->customers = $customers;
    }

    public function setRegistrationBookEntries($registrationBookEntries): void
    {
        $this->registrationBookEntries = $registrationBookEntries;
    }

    public function addRegistrationBookEntry(RegistrationBookEntry $registrationBookEntry)
    {
        $this->registrationBookEntries[] = $registrationBookEntry;

        return $this;
    }

    public function removeRegistrationBookEntry(RegistrationBookEntry $registrationBookEntry): void
    {
        $this->registrationBookEntries->removeElement($registrationBookEntry);
    }

    /**
     * Add customers.
     *
     * @param \App\Entity\Customer $customers
     *
     * @return Reservation
     */
    public function addCustomer(Customer $customers)
    {
        $this->customers[] = $customers;

        return $this;
    }

    /**
     * Remove customers.
     *
     * @param \App\Entity\Customer $customers
     */
    public function removeCustomer(Customer $customers): void
    {
        $this->customers->removeElement($customers);
    }

    /**
     * Get customers.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getCustomers()
    {
        return $this->customers;
    }

    /**
     * Get registrationBookEntries.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getRegistrationBookEntries()
    {
        return $this->registrationBookEntries;
    }

    /**
     * Set booker.
     *
     * @param \App\Entity\Customer $booker
     *
     * @return Reservation
     */
    public function setBooker(Customer $booker = null)
    {
        $this->booker = $booker;

        return $this;
    }

    /**
     * Get booker.
     *
     * @return \App\Entity\Customer
     */
    public function getBooker()
    {
        return $this->booker;
    }

    public function getAmount()
    {
        $interval = $this->startDate->diff($this->endDate);

        return $interval->format('%a');
    }

    /**
     * Set reservationOrigin.
     *
     * @param \App\Entity\ReservationOrigin $reservationOrigin
     *
     * @return Reservation
     */
    public function setReservationOrigin(ReservationOrigin $reservationOrigin = null)
    {
        $this->reservationOrigin = $reservationOrigin;

        return $this;
    }

    /**
     * Get reservationOrigin.
     *
     * @return \App\Entity\ReservationOrigin
     */
    public function getReservationOrigin()
    {
        return $this->reservationOrigin;
    }

    /**
     * Add correspondence.
     *
     * @param \App\Entity\Correspondence $correspondence
     *
     * @return Reservation
     */
    public function addCorrespondence(Correspondence $correspondence)
    {
        $this->correspondences[] = $correspondence;

        return $this;
    }

    /**
     * Remove correspondence.
     *
     * @param \App\Entity\Correspondence $correspondence
     */
    public function removeCorrespondence(Correspondence $correspondence): void
    {
        $this->correspondences->removeElement($correspondence);
    }

    /**
     * Get correspondences.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getCorrespondences()
    {
        return $this->correspondences;
    }

    /**
     * Add invoice.
     *
     * @param \App\Entity\Invoice $invoice
     *
     * @return Reservation
     */
    public function addInvoice(Invoice $invoice)
    {
        $this->invoices[] = $invoice;

        return $this;
    }

    /**
     * Remove invoice.
     *
     * @param \App\Entity\Invoice $invoice
     */
    public function removeInvoice(Invoice $invoice): void
    {
        $this->invoices->removeElement($invoice);
    }

    /**
     * Get invoices.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getInvoices()
    {
        return $this->invoices;
    }

    /**
     * @return Collection|Price[]
     */
    public function getPrices(): Collection
    {
        return $this->prices;
    }

    public function addPrice(Price $price): self
    {
        if (!$this->prices->contains($price)) {
            $this->prices[] = $price;
        }

        return $this;
    }

    public function removePrice(Price $price): self
    {
        $this->prices->removeElement($price);

        return $this;
    }

    public function getReservationStatus(): ?ReservationStatus
    {
        return $this->reservationStatus;
    }

    public function setReservationStatus(?ReservationStatus $reservationStatus): self
    {
        $this->reservationStatus = $reservationStatus;

        return $this;
    }

    public function getUuid(): ?Uuid
    {
        return $this->uuid;
    }

    public function setUuid(Uuid $uuid): self
    {
        $this->uuid = $uuid;

        return $this;
    }
}
