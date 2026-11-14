<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\PaymentMeansCode;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: 'App\Repository\InvoiceRepository')]
#[ORM\Table(name: 'invoices')]
#[ORM\Index(name: 'idx_invoice_number', columns: ['number'])]
#[ORM\Index(name: 'idx_invoice_date', columns: ['date'])]
class Invoice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;
    #[ORM\Column(type: 'string', length: 45)]
    private ?string $number = null;
    #[ORM\Column(type: 'date')]
    private ?\DateTime $date = null;
    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $salutation = null;
    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private ?string $firstname = null;
    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private ?string $lastname = null;
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $company = null;
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $address = null;
    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    private ?string $zip = null;
    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private ?string $city = null;
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $remark = null;
    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private ?string $payment = null;
    #[ORM\Column(type: 'smallint')]
    private int $status;
    /** @var Collection<int, InvoicePosition> */
    #[ORM\OneToMany(targetEntity: InvoicePosition::class, mappedBy: 'invoice')]
    private Collection $positions;
    /** @var Collection<int, InvoiceAppartment> */
    #[ORM\OneToMany(targetEntity: InvoiceAppartment::class, mappedBy: 'invoice')]
    private Collection $appartments;
    /** @var Collection<int, Reservation> */
    #[ORM\ManyToMany(targetEntity: Reservation::class, mappedBy: 'invoices')]
    private Collection $reservations;

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

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $buyerVatId = null;

    /**
     * The day payment falls due. Set when the invoice is created - from the issuer's
     * payment period unless entered otherwise - and kept from then on, so a later
     * change of that period does not rewrite invoices already sent. Null only when
     * the issuer states no period (free-text terms instead).
     */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $paymentDueDate = null;

    /**
     * Branch this invoice was issued for, derived from its reservations at creation time.
     * Drives the number range and the issuer (company) data.
     *
     * This is a snapshot on purpose: moving a room to another branch later must not
     * retroactively change which company issued an existing invoice, so this value and
     * the current Reservation -> Appartment -> Subsidiary chain may legitimately differ.
     * Null means the invoice has no branch — cross-branch or reservation-less invoices —
     * and the global number range and issuer apply.
     */
    #[ORM\ManyToOne(targetEntity: Subsidiary::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Subsidiary $subsidiary = null;

    public function __construct()
    {
        $this->positions = new ArrayCollection();
        $this->appartments = new ArrayCollection();
        $this->reservations = new ArrayCollection();
        $this->status = 1; // not payed yet
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumber(): ?string
    {
        return $this->number;
    }

    /** @throws \TypeError Until the draft has an invoice date. */
    public function getDate(): \DateTime
    {
        return $this->date;
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

    public function getCompany(): ?string
    {
        return $this->company;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function getZip(): ?string
    {
        return $this->zip;
    }

    public function getRemark(): ?string
    {
        return $this->remark;
    }

    public function getRemarkF(): string
    {
        return nl2br($this->remark ?? '');
    }

    /** @return Collection<int, InvoicePosition> */
    public function getPositions(): Collection
    {
        return $this->positions;
    }

    /** @return Collection<int, InvoiceAppartment> */
    public function getAppartments(): Collection
    {
        return $this->appartments;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    /** @return Collection<int, Reservation> */
    public function getReservations(): Collection
    {
        return $this->reservations;
    }

    public function getPayment(): ?string
    {
        return $this->payment;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function setNumber(string $number): void
    {
        $this->number = $number;
    }

    public function getSubsidiary(): ?Subsidiary
    {
        return $this->subsidiary;
    }

    public function setSubsidiary(?Subsidiary $subsidiary): void
    {
        $this->subsidiary = $subsidiary;
    }

    public function setDate(\DateTime $date): void
    {
        $this->date = $date;
    }

    public function setSalutation(?string $salutation): void
    {
        $this->salutation = $salutation;
    }

    public function setFirstname(?string $firstname): void
    {
        $this->firstname = $firstname;
    }

    public function setLastname(?string $lastname): void
    {
        $this->lastname = $lastname;
    }

    public function setCompany(?string $company): void
    {
        $this->company = $company;
    }

    public function setAddress(?string $address): void
    {
        $this->address = $address;
    }

    public function setZip(?string $zip): void
    {
        $this->zip = $zip;
    }

    public function setCity(?string $city): void
    {
        $this->city = $city;
    }

    public function setRemark(?string $remark): void
    {
        $this->remark = $remark;
    }

    public function setPayment(?string $payment): void
    {
        $this->payment = $payment;
    }

    public function setStatus(int $status): void
    {
        $this->status = $status;
    }

    /** @param Collection<int, InvoicePosition> $positions */
    public function setPositions(Collection $positions): void
    {
        $this->positions = $positions;
    }

    /** @param Collection<int, InvoiceAppartment> $appartments */
    public function setAppartments(Collection $appartments): void
    {
        $this->appartments = $appartments;
    }

    /** @param Collection<int, Reservation> $reservations */
    public function setReservations(Collection $reservations): void
    {
        $this->reservations = $reservations;
    }

    public function addAppartment(InvoiceAppartment $appartment): static
    {
        $this->appartments[] = $appartment;

        return $this;
    }

    public function addPosition(InvoicePosition $position): static
    {
        $this->positions[] = $position;

        return $this;
    }

    public function addReservation(Reservation $reservation): static
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
        if (null === $this->cardNumber || strlen($this->cardNumber) < 10) {
            return $this->cardNumber;
        }

        return substr($this->cardNumber, -10);
    }

    public function setCardNumber(?string $cardNumber): static
    {
        $this->cardNumber = $this->maskCardNumber($cardNumber);

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

    public function getBuyerVatId(): ?string
    {
        return $this->buyerVatId;
    }

    public function setBuyerVatId(?string $buyerVatId): static
    {
        $this->buyerVatId = $buyerVatId;

        return $this;
    }

    public function getPaymentDueDate(): ?\DateTimeImmutable
    {
        return $this->paymentDueDate;
    }

    public function setPaymentDueDate(?\DateTimeInterface $paymentDueDate): static
    {
        $this->paymentDueDate = null === $paymentDueDate ? null : \DateTimeImmutable::createFromInterface($paymentDueDate)->setTime(0, 0);

        return $this;
    }

    /**
     * The due date the issuer's payment period gives: invoice date plus that period.
     * What a new invoice starts with; null without a period or an invoice date.
     */
    public function defaultPaymentDueDate(?InvoiceSettingsData $settings): ?\DateTimeImmutable
    {
        $days = $settings?->getPaymentDueDays();
        if (null === $days || null === $this->date) {
            return null;
        }

        return \DateTimeImmutable::createFromInterface($this->date)->setTime(0, 0)->modify('+'.$days.' days');
    }

    /**
     * The earliest start date among the given apartment positions, null without any.
     *
     * Static because an invoice in creation keeps its positions in the session, not
     * in its own collection.
     *
     * @param iterable<InvoiceAppartment> $apartments
     */
    public static function firstArrivalOf(iterable $apartments): ?\DateTimeImmutable
    {
        $first = null;
        foreach ($apartments as $apartment) {
            $start = \DateTimeImmutable::createFromInterface($apartment->getStartDate())->setTime(0, 0);
            if (null === $first || $start < $first) {
                $first = $start;
            }
        }

        return $first;
    }

    /**
     * The latest end date among the given apartment positions, null without any.
     *
     * Static because an invoice in creation keeps its positions in the session, not
     * in its own collection.
     *
     * @param iterable<InvoiceAppartment> $apartments
     */
    public static function lastDepartureOf(iterable $apartments): ?\DateTimeImmutable
    {
        $last = null;
        foreach ($apartments as $apartment) {
            $end = \DateTimeImmutable::createFromInterface($apartment->getEndDate())->setTime(0, 0);
            if (null === $last || $end > $last) {
                $last = $end;
            }
        }

        return $last;
    }

    /**
     * Mask the card number to hide sensitive information (first 2 and last 4 characters are visible).
     */
    private function maskCardNumber(?string $cardNumber): ?string
    {
        if (null === $cardNumber) {
            return null;
        }
        if (strlen($cardNumber) < 6) {
            return $cardNumber; // Not enough characters to mask
        }

        $start = substr($cardNumber, 0, 2);
        $end = substr($cardNumber, -4);
        $masked = str_repeat('X', strlen($cardNumber) - 6);

        return $start.$masked.$end;
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
