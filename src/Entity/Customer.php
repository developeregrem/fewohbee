<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity (repositoryClass="App\Repository\CustomerRepository")
 * @ORM\Table(name="customers")
 **/
class Customer
{
    /** @ORM\Id @ORM\Column(type="integer") @ORM\GeneratedValue * */
    private $id;

    /** @ORM\Column(type="string", length=20) * */
    private $salutation;

    /** @ORM\Column(type="string", length=45, nullable=true) * */
    private $firstname;

    /** @ORM\Column(type="string", length=45) * */
    private $lastname;

    /** @ORM\Column(type="date", nullable=true) * */
    private $birthday;

    /** @ORM\Column(type="string", length=255, nullable=true) * */
    private $company;

    /** @ORM\Column(type="string", length=150, nullable=true) * */
    private $address;

    /** @ORM\Column(type="string", length=10, nullable=true) * */
    private $zip;

    /** @ORM\Column(type="string", length=45, nullable=true) * */
    private $city;

    /** @ORM\Column(type="string", length=45, nullable=true) * */
    private $country;

    /** @ORM\Column(type="string", length=45, nullable=true) * */
    private $phone;

    /** @ORM\Column(type="string", length=45, nullable=true) * */
    private $fax;

    /** @ORM\Column(type="string", length=45, nullable=true) * */
    private $mobile_phone;

    /** @ORM\Column(type="string", length=100, nullable=true) * */
    private $email;

    /** @ORM\Column(type="string", length=255, nullable=true) * */
    private $remark;

    /**
     * @ORM\ManyToMany(targetEntity="Reservation", mappedBy="customers")
     */
    private $reservations;

    /**
     * @ORM\OneToMany(targetEntity="RegistrationBookEntry", mappedBy="customer")
     */
    private $registrationBookEntries;

    /**
     * @ORM\OneToMany(targetEntity="Reservation", mappedBy="booker")
     */
    private $bookedReservations;
    
    /**
     * @ORM\ManyToMany(targetEntity="CustomerAddresses", inversedBy="customers")
     * @ORM\JoinTable(name="customer_has_address")
     */
    private $customerAddresses;

    public function __construct()
    {
        $this->reservations = new ArrayCollection();
        $this->registrationBookEntries = new ArrayCollection();
        $this->bookedReservations = new ArrayCollection();
        $this->customerAddresses = new ArrayCollection();
    }

    public function getId()
    {
        return $this->id;
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

    public function getBirthday()
    {
        return $this->birthday;
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

    public function getCity()
    {
        return $this->city;
    }

    public function getCountry()
    {
        return $this->country;
    }

    public function getPhone()
    {
        return $this->phone;
    }

    public function getFax()
    {
        return $this->fax;
    }

    public function getMobilePhone()
    {
        return $this->mobile_phone;
    }

    public function getEmail()
    {
        return $this->email;
    }

    public function getReservations()
    {
        return $this->reservations;
    }

    public function setId($id)
    {
        $this->id = $id;
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

    public function setBirthday($birthday)
    {
        $this->birthday = $birthday;
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

    public function setCountry($country)
    {
        $this->country = $country;
    }

    public function setPhone($phone)
    {
        $this->phone = $phone;
    }

    public function setFax($fax)
    {
        $this->fax = $fax;
    }

    public function setMobilePhone($mobile_phone)
    {
        $this->mobile_phone = $mobile_phone;
    }

    public function setEmail($email)
    {
        $this->email = $email;
    }

    public function setReservations($reservations)
    {
        $this->reservations = $reservations;
    }

    public function addReservation(\App\Entity\Reservation $reservation)
    {
        $this->reservations[] = $reservation;
        return $this;
    }

    public function removeReservation(\App\Entity\Reservation $reservation)
    {
        $this->reservations->removeElement($reservation);
    }

    public function getRemark()
    {
        return $this->remark;
    }
    
    public function getRemarkF()
    {
        return nl2br($this->remark);
    }

    public function setRemark($remark)
    {
        $this->remark = $remark;
    }

    public function getRegistrationBookEntries()
    {
        return $this->registrationBookEntries;
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
     * Add bookedReservations
     *
     * @param \App\Entity\Reservation $bookedReservations
     * @return Customer
     */
    public function addBookedReservation(\App\Entity\Reservation $bookedReservations)
    {
        $this->bookedReservations[] = $bookedReservations;

        return $this;
    }

    /**
     * Remove bookedReservations
     *
     * @param \App\Entity\Reservation $bookedReservations
     */
    public function removeBookedReservation(\App\Entity\Reservation $bookedReservations)
    {
        $this->bookedReservations->removeElement($bookedReservations);
    }

    /**
     * Get bookedReservations
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getBookedReservations()
    {
        return $this->bookedReservations;
    }

    /**
     * Add customerAddress
     *
     * @param \App\Entity\CustomerAddresses $customerAddress
     *
     * @return Customer
     */
    public function addCustomerAddress(\App\Entity\CustomerAddresses $customerAddress)
    {
        $this->customerAddresses[] = $customerAddress;

        return $this;
    }

    /**
     * Remove customerAddress
     *
     * @param \App\Entity\CustomerAddresses $customerAddress
     */
    public function removeCustomerAddress(\App\Entity\CustomerAddresses $customerAddress)
    {
        $this->customerAddresses->removeElement($customerAddress);
    }

    /**
     * Get customerAddresses
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getCustomerAddresses()
    {
        return $this->customerAddresses;
    }
}
