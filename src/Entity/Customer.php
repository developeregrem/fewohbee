<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\IDCardType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: 'App\Repository\CustomerRepository')]
#[ORM\Table(name: 'customers')]
class Customer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;
    #[ORM\Column(type: 'string', length: 20)]
    private $salutation;
    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private $firstname;
    #[ORM\Column(type: 'string', length: 45)]
    private $lastname;
    #[ORM\Column(type: 'date', nullable: true)]
    private $birthday;
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $remark = null;
    #[ORM\ManyToMany(targetEntity: 'Reservation', mappedBy: 'customers')]
    private Collection $reservations;
    #[ORM\OneToMany(mappedBy: 'customer', targetEntity: 'RegistrationBookEntry')]
    private Collection $registrationBookEntries;
    #[ORM\OneToMany(mappedBy: 'booker', targetEntity: 'Reservation')]
    private Collection $bookedReservations;
    #[ORM\ManyToMany(targetEntity: 'CustomerAddresses', inversedBy: 'customers')]
    #[ORM\JoinTable(name: 'customer_has_address')]
    private Collection $customerAddresses;

    #[ORM\Column(type: 'string', nullable: true, enumType: IDCardType::class)]
    private ?IDCardType $idType = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $IDNumber = null;

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

    public function getReservations(): ArrayCollection|Collection
    {
        return $this->reservations;
    }

    public function setId($id): void
    {
        $this->id = $id;
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

    public function setBirthday($birthday): void
    {
        $this->birthday = $birthday;
    }

    public function setReservations($reservations): void
    {
        $this->reservations = $reservations;
    }

    public function addReservation(Reservation $reservation): static
    {
        $this->reservations[] = $reservation;

        return $this;
    }

    public function removeReservation(Reservation $reservation): void
    {
        $this->reservations->removeElement($reservation);
    }

    public function getRemark(): ?string
    {
        return $this->remark;
    }

    public function getRemarkF(): string
    {
        return nl2br($this->remark);
    }

    public function setRemark($remark): void
    {
        $this->remark = $remark;
    }

    public function getRegistrationBookEntries(): ArrayCollection
    {
        return $this->registrationBookEntries;
    }

    public function setRegistrationBookEntries($registrationBookEntries): void
    {
        $this->registrationBookEntries = $registrationBookEntries;
    }

    public function addRegistrationBookEntry(RegistrationBookEntry $registrationBookEntry): static
    {
        $this->registrationBookEntries[] = $registrationBookEntry;

        return $this;
    }

    public function removeRegistrationBookEntry(RegistrationBookEntry $registrationBookEntry): void
    {
        $this->registrationBookEntries->removeElement($registrationBookEntry);
    }

    /**
     * Add bookedReservations.
     *
     * @param Reservation $bookedReservations
     * @return Customer
     */
    public function addBookedReservation(Reservation $bookedReservations): static
    {
        $this->bookedReservations[] = $bookedReservations;

        return $this;
    }

    /**
     * Remove bookedReservations.
     *
     * @param Reservation $bookedReservations
     */
    public function removeBookedReservation(Reservation $bookedReservations): void
    {
        $this->bookedReservations->removeElement($bookedReservations);
    }

    /**
     * Get bookedReservations.
     *
     * @return ArrayCollection|Collection
     */
    public function getBookedReservations(): ArrayCollection|Collection
    {
        return $this->bookedReservations;
    }

    /**
     * Add customerAddress.
     *
     * @param CustomerAddresses $customerAddress
     *
     * @return Customer
     */
    public function addCustomerAddress(CustomerAddresses $customerAddress): static
    {
        $this->customerAddresses[] = $customerAddress;

        return $this;
    }

    /**
     * Remove customerAddress.
     *
     * @param CustomerAddresses $customerAddress
     */
    public function removeCustomerAddress(CustomerAddresses $customerAddress): void
    {
        $this->customerAddresses->removeElement($customerAddress);
    }

    /**
     * Get customerAddresses.
     *
     * @return ArrayCollection|Collection
     */
    public function getCustomerAddresses(): ArrayCollection|Collection
    {
        return $this->customerAddresses;
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

    /**
     * @return IDCardType|null
     */
    public function getIdType(): ?IDCardType
    {
        return $this->idType;
    }

    /**
     * @param IDCardType|null $idType
     */
    public function setIdType(?IDCardType $idType): void
    {
        $this->idType = $idType;
    }
}
