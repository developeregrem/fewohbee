<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\IDCardType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: 'App\Repository\RegistrationBookEntryRepository')]
#[ORM\Table(name: 'registration_book')]
class RegistrationBookEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;
    #[ORM\Column(type: 'string', length: 10)]
    private $number;
    #[ORM\Column(type: 'datetime', nullable: false)]
    private $date;
    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private $salutation;
    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private $firstname;
    #[ORM\Column(type: 'string', length: 45, nullable: false)]
    private $lastname;
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private $company;
    #[ORM\Column(type: 'date', nullable: true)]
    private $birthday;
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private $address;
    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    private $zip;
    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private $city;
    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private $country;
    #[ORM\Column(type: 'string', length: 4, nullable: false)]
    private $year;
    #[ORM\ManyToOne(targetEntity: 'Customer', inversedBy: 'registrationBookEntries')]
    private $customer;
    #[ORM\ManyToOne(targetEntity: 'Reservation', inversedBy: 'registrationBookEntries')]
    private $reservation;

    #[ORM\Column(type: 'string', nullable: true, enumType: IDCardType::class)]
    private ?IDCardType $idType = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $IDNumber = null;

    public function __construct()
    {
        $this->date = new \DateTime();
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

    public function getCity()
    {
        return $this->city;
    }

    public function setId($id): void
    {
        $this->id = $id;
    }

    public function setNumber($number): void
    {
        $this->number = $number;
    }

    public function setDate($date): void
    {
        $this->date = $date;
    }

    public function setSalutation($salutation): void
    {
        $this->salutation = $salutation;
    }

    public function setFirstname($firstname): void
    {
        $this->firstname = $firstname;
    }

    public function setLastname($lastname): void
    {
        $this->lastname = $lastname;
    }

    public function setCompany($company): void
    {
        $this->company = $company;
    }

    public function setAddress($address): void
    {
        $this->address = $address;
    }

    public function setZip($zip): void
    {
        $this->zip = $zip;
    }

    public function setCity($city): void
    {
        $this->city = $city;
    }

    public function getCountry()
    {
        return $this->country;
    }

    public function getCustomer()
    {
        return $this->customer;
    }

    public function getReservation()
    {
        return $this->reservation;
    }

    public function setCountry($country): void
    {
        $this->country = $country;
    }

    public function setCustomer($customer): void
    {
        $this->customer = $customer;
    }

    public function setReservation($reservation): void
    {
        $this->reservation = $reservation;
    }

    /**
     * Set birthday.
     *
     * @param \DateTime $birthday
     *
     * @return RegistrationBookEntry
     */
    public function setBirthday($birthday)
    {
        $this->birthday = $birthday;

        return $this;
    }

    /**
     * Get birthday.
     *
     * @return \DateTime
     */
    public function getBirthday()
    {
        return $this->birthday;
    }

    /**
     * Set year.
     *
     * @param string $year
     *
     * @return RegistrationBookEntry
     */
    public function setYear($year)
    {
        $this->year = $year;

        return $this;
    }

    /**
     * Get year.
     *
     * @return string
     */
    public function getYear()
    {
        return $this->year;
    }

    public function getIDNumber(): ?string
    {
        return $this->IDNumber;
    }

    public function setIDNumber(?string $IDNumber): self
    {
        $this->IDNumber = $IDNumber;

        return $this;
    }

    public function getIdType(): ?IDCardType
    {
        return $this->idType;
    }

    public function setIdType(?IDCardType $idType): void
    {
        $this->idType = $idType;
    }
}
