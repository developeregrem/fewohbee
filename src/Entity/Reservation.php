<?php
namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\ReservationRepository")
 * @ORM\Table(name="reservations")
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

    /** @ORM\Column(type="smallint") * */
    private $status;

    /** @ORM\Column(name="option_date", type="date", nullable=true) * */
    private $optionDate;

    /** @ORM\Column(type="text", nullable=true) * */
    private $remark;

    /** @ORM\Column(name="reservation_date", type="date") * */
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
     * @ORM\OneToMany(targetEntity="Correspondence", mappedBy="reservation", cascade="remove")
     */
    private $correspondences;

    public function __construct()
    {
        $this->reservationDate = new \DateTime('now');
        $this->registrationBookEntries = new ArrayCollection();
        $this->customers = new ArrayCollection();
        $this->correspondences = new ArrayCollection();
        $this->invoices = new ArrayCollection();
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

    public function getStatus()
    {
        return $this->status;
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

    public function getAppartment()
    {
        return $this->appartment;
    }

    public function getCustomer()
    {
        return $this->customer;
    }

    public function setId($id)
    {
        $this->id = $id;
    }

    public function setStartDate($startDate)
    {
        $this->startDate = $startDate;
    }

    public function setEndDate($endDate)
    {
        $this->endDate = $endDate;
    }

    public function setPersons($persons)
    {
        $this->persons = $persons;
    }

    public function setStatus($status)
    {
        $this->status = $status;
    }

    public function setOptionDate($optionDate)
    {
        $this->optionDate = $optionDate;
    }

    public function setRemark($remark)
    {
        $this->remark = $remark;
    }

    public function setReservationDate($reservationDate)
    {
        $this->reservationDate = $reservationDate;
    }

    public function setAppartment($appartment)
    {
        $this->appartment = $appartment;
    }

    public function setCustomers($customers)
    {
        $this->customers = $customers;
    }

    public function setRegistrationBookEntries($registrationBookEntries)
    {
        $this->registrationBookEntries = $registrationBookEntries;
    }

    public function addRegistrationBookEntry(\App\Entity\RegistrationBookEntry $registrationBookEntry)
    {
        $this->registrationBookEntries[] = $registrationBookEntry;
        return $this;
    }

    public function removeRegistrationBookEntry(\App\Entity\RegistrationBookEntry $registrationBookEntry)
    {
        $this->registrationBookEntries->removeElement($registrationBookEntry);
    }

    /**
     * Add customers
     *
     * @param \App\Entity\Customer $customers
     * @return Reservation
     */
    public function addCustomer(\App\Entity\Customer $customers)
    {
        $this->customers[] = $customers;

        return $this;
    }

    /**
     * Remove customers
     *
     * @param \App\Entity\Customer $customers
     */
    public function removeCustomer(\App\Entity\Customer $customers)
    {
        $this->customers->removeElement($customers);
    }

    /**
     * Get customers
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getCustomers()
    {
        return $this->customers;
    }

    /**
     * Get registrationBookEntries
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getRegistrationBookEntries()
    {
        return $this->registrationBookEntries;
    }

    /**
     * Set booker
     *
     * @param \App\Entity\Customer $booker
     * @return Reservation
     */
    public function setBooker(\App\Entity\Customer $booker = null)
    {
        $this->booker = $booker;

        return $this;
    }

    /**
     * Get booker
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
     * Set reservationOrigin
     *
     * @param \App\Entity\ReservationOrigin $reservationOrigin
     * @return Reservation
     */
    public function setReservationOrigin(\App\Entity\ReservationOrigin $reservationOrigin = null)
    {
        $this->reservationOrigin = $reservationOrigin;

        return $this;
    }

    /**
     * Get reservationOrigin
     *
     * @return \App\Entity\ReservationOrigin 
     */
    public function getReservationOrigin()
    {
        return $this->reservationOrigin;
    }

    /**
     * Add correspondence
     *
     * @param \App\Entity\Correspondence $correspondence
     *
     * @return Reservation
     */
    public function addCorrespondence(\App\Entity\Correspondence $correspondence)
    {
        $this->correspondences[] = $correspondence;

        return $this;
    }

    /**
     * Remove correspondence
     *
     * @param \App\Entity\Correspondence $correspondence
     */
    public function removeCorrespondence(\App\Entity\Correspondence $correspondence)
    {
        $this->correspondences->removeElement($correspondence);
    }

    /**
     * Get correspondences
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getCorrespondences()
    {
        return $this->correspondences;
    }

    /**
     * Add invoice
     *
     * @param \App\Entity\Invoice $invoice
     *
     * @return Reservation
     */
    public function addInvoice(\App\Entity\Invoice $invoice)
    {
        $this->invoices[] = $invoice;

        return $this;
    }

    /**
     * Remove invoice
     *
     * @param \App\Entity\Invoice $invoice
     */
    public function removeInvoice(\App\Entity\Invoice $invoice)
    {
        $this->invoices->removeElement($invoice);
    }

    /**
     * Get invoices
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getInvoices()
    {
        return $this->invoices;
    }
}
