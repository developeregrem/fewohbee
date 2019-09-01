<?php
namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\InvoiceRepository")
 * @ORM\Table(name="invoices")
 **/
class Invoice
{
    /** @ORM\Id @ORM\Column(type="integer") @ORM\GeneratedValue * */
    private $id;

    /** @ORM\Column(type="string", length=45) * */
    private $number;

    /** @ORM\Column(type="date") * */
    private $date;

    /** @ORM\Column(type="string", length=20, nullable=true) * */
    private $salutation;

    /** @ORM\Column(type="string", length=45, nullable=true) * */
    private $firstname;

    /** @ORM\Column(type="string", length=45, nullable=true) * */
    private $lastname;

    /** @ORM\Column(type="string", length=255, nullable=true) * */
    private $company;

    /** @ORM\Column(type="string", length=150, nullable=true) * */
    private $address;

    /** @ORM\Column(type="string", length=10, nullable=true) * */
    private $zip;

    /** @ORM\Column(type="string", length=45, nullable=true) * */
    private $city;

    /** @ORM\Column(type="text", nullable=true) * */
    private $remark;

    /** @ORM\Column(type="string", length=45, nullable=true) * */
    private $payment;

    /** @ORM\Column(type="smallint") * */
    private $status;

    /**
     * @ORM\OneToMany(targetEntity="InvoicePosition", mappedBy="invoice")
     */
    private $positions;

    /**
     * @ORM\OneToMany(targetEntity="InvoiceAppartment", mappedBy="invoice")
     */
    private $appartments;

    /**
     * @ORM\ManyToMany(targetEntity="Reservation", mappedBy="invoices")
     */
    private $reservations;

    public function __construct()
    {
        $this->positions = new ArrayCollection();
        $this->appartments = new ArrayCollection();
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

    public function getDate()
    {
        return $this->date;
    }

    public function getSalutation()
    {
        return $this->salutation;
    }

    public function getFirstname()
    {
        return $this->firstname;
    }

    public function getLastname()
    {
        return $this->lastname;
    }

    public function getCompany()
    {
        return $this->company;
    }

    public function getAddress()
    {
        return $this->address;
    }

    public function getZip()
    {
        return $this->zip;
    }

    public function getRemark()
    {
        return $this->remark;
    }
    
    public function getRemarkF()
    {
        return nl2br($this->remark);
    }

    public function getPositions()
    {
        return $this->positions;
    }

    public function getAppartments()
    {
        return $this->appartments;
    }

    public function getCity()
    {
        return $this->city;
    }

    public function getReservations()
    {
        return $this->reservations;
    }

    public function getPayment()
    {
        return $this->payment;
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function setId($id)
    {
        $this->id = $id;
    }

    public function setNumber($number)
    {
        $this->number = $number;
    }

    public function setDate($date)
    {
        $this->date = $date;
    }

    public function setSalutation($salutation)
    {
        $this->salutation = $salutation;
    }

    public function setFirstname($firstname)
    {
        $this->firstname = $firstname;
    }

    public function setLastname($lastname)
    {
        $this->lastname = $lastname;
    }

    public function setCompany($company)
    {
        $this->company = $company;
    }

    public function setAddress($address)
    {
        $this->address = $address;
    }

    public function setZip($zip)
    {
        $this->zip = $zip;
    }

    public function setCity($city)
    {
        $this->city = $city;
    }

    public function setRemark($remark)
    {
        $this->remark = $remark;
    }

    public function setPayment($payment)
    {
        $this->payment = $payment;
    }

    public function setStatus($status)
    {
        $this->status = $status;
    }

    public function setPositions($positions)
    {
        $this->positions = $positions;
    }

    public function setAppartments($appartments)
    {
        $this->appartments = $appartments;
    }

    public function setReservations($reservations)
    {
        $this->reservations = $reservations;
    }

    public function addAppartment(\App\Entity\InvoiceAppartment $appartment)
    {
        $this->appartments[] = $appartment;
        return $this;
    }

    public function addPosition(\App\Entity\InvoicePosition $position)
    {
        $this->positions[] = $position;
        return $this;
    }

    public function addReservation(\App\Entity\Reservation $reservation)
    {
        $this->reservations[] = $reservation;
        return $this;
    }

    public function removeAppartment(\App\Entity\InvoiceAppartment $appartment)
    {
        $this->appartments->removeElement($appartment);
    }

    public function removePosition(\App\Entity\InvoicePosition $position)
    {
        $this->positions->removeElement($position);
    }

    public function removeReservation(\App\Entity\Reservation $reservation)
    {
        $this->reservations->removeElement($reservation);
    }
}
