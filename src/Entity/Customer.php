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
    /** @var int|'new'|null */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int|string|null $id = null;
    #[ORM\Column(type: 'string', length: 20)]
    private ?string $salutation = null;
    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private ?string $firstname = null;
    #[ORM\Column(type: 'string', length: 45)]
    private ?string $lastname = null;
    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTime $birthday = null;
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $remark = null;
    /** @var Collection<int, Reservation> */
    #[ORM\ManyToMany(targetEntity: Reservation::class, mappedBy: 'customers')]
    private Collection $reservations;
    /** @var Collection<int, RegistrationBookEntry> */
    #[ORM\OneToMany(mappedBy: 'customer', targetEntity: RegistrationBookEntry::class)]
    private Collection $registrationBookEntries;
    /** @var Collection<int, Reservation> */
    #[ORM\OneToMany(mappedBy: 'booker', targetEntity: Reservation::class)]
    private Collection $bookedReservations;
    /** @var Collection<int, CustomerAddresses> */
    #[ORM\ManyToMany(targetEntity: CustomerAddresses::class, inversedBy: 'customers')]
    #[ORM\JoinTable(name: 'customer_has_address')]
    private Collection $customerAddresses;

    #[ORM\Column(type: 'string', nullable: true, enumType: IDCardType::class)]
    private ?IDCardType $idType = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $IDNumber = null;

    /** Nationality as ISO 3166-1 alpha-2 code, e.g. "DE"; printed on the registration form. */
    #[ORM\Column(length: 2, nullable: true)]
    private ?string $nationality = null;

    /**
     * Language for mails to this guest, a guest locale such as "en"; null means the installation
     * language. Taken from the language of an online booking or check-in, editable in the backend.
     */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $locale = null;

    public function __construct()
    {
        $this->reservations = new ArrayCollection();
        $this->registrationBookEntries = new ArrayCollection();
        $this->bookedReservations = new ArrayCollection();
        $this->customerAddresses = new ArrayCollection();
    }

    /** @return int|'new'|null */
    public function getId(): int|string|null
    {
        return $this->id;
    }

    public function getSalutation(): ?string
    {
        return $this->salutation;
    }

    public function getFirstname(): ?string
    {
        return $this->firstname;
    }

    public function getLastname(): ?string
    {
        return $this->lastname;
    }

    public function getBirthday(): ?\DateTime
    {
        return $this->birthday;
    }

    /** @return Collection<int, Reservation> */
    public function getReservations(): ArrayCollection|Collection
    {
        return $this->reservations;
    }

    /** @param int|'new' $id */
    public function setId(int|string $id): void
    {
        $this->id = $id;
    }

    public function setSalutation(string $salutation): void
    {
        $this->salutation = $salutation;
    }

    public function setFirstname(?string $firstname): void
    {
        $this->firstname = $firstname;
    }

    public function setLastname(string $lastname): void
    {
        $this->lastname = $lastname;
    }

    public function setBirthday(?\DateTime $birthday): void
    {
        $this->birthday = $birthday;
    }

    /** @param Collection<int, Reservation> $reservations */
    public function setReservations(Collection $reservations): void
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
        return nl2br($this->remark ?? '');
    }

    public function setRemark(?string $remark): void
    {
        $this->remark = $remark;
    }

    /** @return Collection<int, RegistrationBookEntry> */
    public function getRegistrationBookEntries(): ArrayCollection|Collection
    {
        return $this->registrationBookEntries;
    }

    /** @param Collection<int, RegistrationBookEntry> $registrationBookEntries */
    public function setRegistrationBookEntries(Collection $registrationBookEntries): void
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
     * @return $this
     */
    public function addBookedReservation(Reservation $bookedReservations): static
    {
        $this->bookedReservations[] = $bookedReservations;

        return $this;
    }

    /**
     * Remove bookedReservations.
     */
    public function removeBookedReservation(Reservation $bookedReservations): void
    {
        $this->bookedReservations->removeElement($bookedReservations);
    }

    /**
     * Get bookedReservations.
     *
     * @return Collection<int, Reservation>
     */
    public function getBookedReservations(): ArrayCollection|Collection
    {
        return $this->bookedReservations;
    }

    /**
     * Add customerAddress.
     *
     * @return $this
     */
    public function addCustomerAddress(CustomerAddresses $customerAddress): static
    {
        $this->customerAddresses[] = $customerAddress;

        return $this;
    }

    /**
     * Remove customerAddress.
     */
    public function removeCustomerAddress(CustomerAddresses $customerAddress): void
    {
        $this->customerAddresses->removeElement($customerAddress);
    }

    /**
     * Get customerAddresses.
     *
     * @return Collection<int, CustomerAddresses>
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

    public function getIdType(): ?IDCardType
    {
        return $this->idType;
    }

    public function setIdType(?IDCardType $idType): void
    {
        $this->idType = $idType;
    }

    public function getNationality(): ?string
    {
        return $this->nationality;
    }

    public function setNationality(?string $nationality): void
    {
        $nationality = null === $nationality ? '' : strtoupper(trim($nationality));
        $this->nationality = '' === $nationality ? null : $nationality;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function setLocale(?string $locale): void
    {
        $locale = null === $locale ? '' : trim($locale);
        $this->locale = '' === $locale ? null : $locale;
    }
}
