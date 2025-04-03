<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\PaymentMeansCode;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: 'App\Repository\InvoiceRepository')]
#[ORM\Table(name: 'invoices')]
class Invoice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;
    #[ORM\Column(type: 'string', length: 45)]
    private $number;
    #[ORM\Column(type: 'date')]
    private $date;
    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private $salutation;
    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private $firstname;
    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private $lastname;
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private $company;
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private $address;
    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    private $zip;
    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private $city;
    #[ORM\Column(type: 'text', nullable: true)]
    private $remark;
    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private $payment;
    #[ORM\Column(type: 'smallint')]
    private $status;
    #[ORM\OneToMany(targetEntity: 'InvoicePosition', mappedBy: 'invoice')]
    private $positions;
    #[ORM\OneToMany(targetEntity: 'InvoiceAppartment', mappedBy: 'invoice')]
    private $appartments;
    #[ORM\ManyToMany(targetEntity: 'Reservation', mappedBy: 'invoices')]
    private $reservations;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 2, nullable: true)]
    private ?string $country = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $buyerReference = null;

    #[ORM\Column(nullable: true, enumType: PaymentMeansCode::class)]
    private ?PaymentMeansCode $paymentMeans = null;

    #[ORM\Column(length: 19, nullable: true)]
    private ?string $cardNumber = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $customerIBAN = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $cardHolder = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $mandateReference = null;

    public function __construct()
    {
        $this->positions = new ArrayCollection();
        $this->appartments = new ArrayCollection();
        $this->reservations = new ArrayCollection();
        $this->status = 1; // not payed yet
    }

    public function getId()
    {
        return $this->id;
    }

    public function getNumber()
    {
        return $this->number;
    }

    public function getDate():  \DateTime
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

    public function setRemark($remark): void
    {
        $this->remark = $remark;
    }

    public function setPayment($payment): void
    {
        $this->payment = $payment;
    }

    public function setStatus($status): void
    {
        $this->status = $status;
    }

    public function setPositions($positions): void
    {
        $this->positions = $positions;
    }

    public function setAppartments($appartments): void
    {
        $this->appartments = $appartments;
    }

    public function setReservations($reservations): void
    {
        $this->reservations = $reservations;
    }

    public function addAppartment(InvoiceAppartment $appartment)
    {
        $this->appartments[] = $appartment;

        return $this;
    }

    public function addPosition(InvoicePosition $position)
    {
        $this->positions[] = $position;

        return $this;
    }

    public function addReservation(Reservation $reservation)
    {
        $this->reservations[] = $reservation;

        return $this;
    }

    public function removeAppartment(InvoiceAppartment $appartment): void
    {
        $this->appartments->removeElement($appartment);
    }

    public function removePosition(InvoicePosition $position): void
    {
        $this->positions->removeElement($position);
    }

    public function removeReservation(Reservation $reservation): void
    {
        $this->reservations->removeElement($reservation);
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): static
    {
        $this->country = $country;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getBuyerReference(): ?string
    {
        return $this->buyerReference;
    }

    public function setBuyerReference(?string $buyerReference): static
    {
        $this->buyerReference = $buyerReference;

        return $this;
    }

    public function getPaymentMeans(): ?PaymentMeansCode
    {
        return $this->paymentMeans;
    }

    public function setPaymentMeans(?PaymentMeansCode $paymentMeans): static
    {
        $this->paymentMeans = $paymentMeans;

        return $this;
    }

    public function getCardNumber(): ?string
    {
        return $this->cardNumber;
    }

    public function getCardNumberShort(): ?string
    {
        if (strlen($this->cardNumber) < 10) {
            return $this->cardNumber; // Not enough characters to mask
        }
        return substr($this->cardNumber, -10);
    }

    public function setCardNumber(?string $cardNumber): static
    {
        $this->cardNumber = $this->maskCardNumber($cardNumber);;

        return $this;
    }

    public function getCustomerIBAN(): ?string
    {
        return $this->customerIBAN;
    }

    public function setCustomerIBAN(?string $customerIBAN): static
    {
        $this->customerIBAN = $customerIBAN;

        return $this;
    }

    public function getCardHolder(): ?string
    {
        return $this->cardHolder;
    }

    public function setCardHolder(?string $cardHolder): static
    {
        $this->cardHolder = $cardHolder;

        return $this;
    }

    /**
     * Mask the card number to hide sensitive information (first 2 and last 4 characters are visible)
     */ 
    private function maskCardNumber(?string $cardNumber): ?string
    {
        if ($cardNumber === null) {
            return null;
        }
        if (strlen($cardNumber) < 6) {
            return $cardNumber; // Not enough characters to mask
        }

        $start = substr($cardNumber, 0, 2);
        $end = substr($cardNumber, -4);
        $masked = str_repeat('X', strlen($cardNumber) - 6);

        return $start . $masked . $end;
    }

    public function getMandateReference(): ?string
    {
        return $this->mandateReference;
    }

    public function setMandateReference(?string $mandateReference): static
    {
        $this->mandateReference = $mandateReference;

        return $this;
    }
}
