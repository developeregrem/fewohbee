<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'invoice_appartments')]
class InvoiceAppartment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private int|string|null $id = null;
    #[ORM\Column(type: 'string', length: 10)]
    #[Assert\NotBlank]
    private ?string $number = null;
    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank]
    private ?string $description = null;
    #[ORM\Column(type: 'smallint')]
    #[Assert\NotNull]
    #[Assert\Positive]
    private ?int $beds = null;
    #[ORM\Column(type: 'smallint')]
    #[Assert\NotNull]
    #[Assert\Positive]
    private ?int $persons = null;
    #[ORM\Column(name: 'start_date', type: 'date')]
    #[Assert\NotNull]
    private ?\DateTime $startDate = null;
    #[ORM\Column(name: 'end_date', type: 'date')]
    #[Assert\NotNull]
    private ?\DateTime $endDate = null;
    /** @var int|float|numeric-string|null Keep the assigned representation, including DBAL decimal strings. */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    private int|float|string|null $price = null;
    /** @var float|numeric-string Keep DBAL strings intact to avoid false updates after hydration. */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Assert\PositiveOrZero]
    private float|string $vat;
    #[ORM\ManyToOne(targetEntity: Invoice::class, inversedBy: 'appartments')]
    private ?Invoice $invoice = null;
    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $includesVat = null;
    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $isFlatPrice = null;
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isPerRoom;
    #[ORM\ManyToOne(targetEntity: AccountingAccount::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?AccountingAccount $revenueAccount = null;
    /** Snapshot: the room was an event space, so the position counts days and ends on its last day. */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $eventSpace = false;

    public function __construct()
    {
        $this->isFlatPrice = false;
        $this->includesVat = false;
        $this->isPerRoom = true;
    }

    public function getId(): int|string|null
    {
        return $this->id;
    }

    public function getNumber(): ?string
    {
        return $this->number;
    }

    public function getBeds(): ?int
    {
        return $this->beds;
    }

    public function getPersons(): ?int
    {
        return $this->persons;
    }

    public function getStartDate(): \DateTime
    {
        return $this->startDate;
    }

    public function getEndDate(): \DateTime
    {
        return $this->endDate;
    }

    /** @return int|float|numeric-string|null */
    public function getPrice(): int|float|string|null
    {
        return $this->price;
    }

    public function getVat(): float
    {
        return (float) $this->vat;
    }

    public function getInvoice(): ?Invoice
    {
        return $this->invoice;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getAmount(): int
    {
        if ($this->isFlatPrice) {
            return 1;
        }
        // else
        $interval = $this->startDate->diff($this->endDate);
        $days = max(1, (int) $interval->format('%a'));

        return $days * ($this->isPerRoom ? 1 : $this->persons);
    }

    public function getTotalPriceRaw(): float
    {
        return $this->isFlatPrice ? (float) $this->price : (float) $this->price * $this->getAmount();
    }

    public function getTotalPrice(): string
    {
        $price = ($this->isFlatPrice ? $this->price : $this->price * $this->getAmount());

        return number_format((float) $price, 2, ',', '.');
    }

    public function getPriceFormated(): string
    {
        return number_format((float) $this->price, 2, ',', '.');
    }

    public function setId(int|string $id): void
    {
        $this->id = $id;
    }

    public function setNumber(?string $number): void
    {
        $this->number = $number;
    }

    public function setBeds(?int $beds): void
    {
        $this->beds = $beds;
    }

    public function setPersons(?int $persons): void
    {
        $this->persons = $persons;
    }

    public function setStartDate(?\DateTime $startDate): void
    {
        $this->startDate = $startDate;
    }

    public function isEventSpace(): bool
    {
        return $this->eventSpace;
    }

    public function setEventSpace(bool $eventSpace): self
    {
        $this->eventSpace = $eventSpace;

        return $this;
    }

    /**
     * The last day of an event: the stored end is the day after it, like at the reservation.
     * Never before the start, also for same-day entries. A position without dates has none yet.
     */
    public function getLastDay(): ?\DateTime
    {
        if (!$this->endDate instanceof \DateTime) {
            return null;
        }
        $lastDay = (clone $this->endDate)->modify('-1 day');

        return $lastDay < $this->startDate ? clone $this->startDate : $lastDay;
    }

    /** Takes the last day of an event as typed into the position form and stores the day after it. */
    public function setLastDay(?\DateTime $lastDay): void
    {
        $this->endDate = null === $lastDay ? null : (clone $lastDay)->modify('+1 day');
    }

    public function setEndDate(?\DateTime $endDate): void
    {
        $this->endDate = $endDate;
    }

    /** @param int|float|numeric-string|null $price */
    public function setPrice(int|float|string|null $price): void
    {
        $this->price = $price;
    }

    public function setVat(int|float|string|null $vat): void
    {
        $this->vat = (float) $vat;
    }

    public function setInvoice(?Invoice $invoice): void
    {
        $this->invoice = $invoice;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getIncludesVat(): ?bool
    {
        return $this->includesVat;
    }

    public function setIncludesVat(bool $includesVat): self
    {
        $this->includesVat = $includesVat;

        return $this;
    }

    public function getIsFlatPrice(): ?bool
    {
        return $this->isFlatPrice;
    }

    public function setIsFlatPrice(bool $isFlatPrice): self
    {
        $this->isFlatPrice = $isFlatPrice;
        if ($isFlatPrice) {
            $this->isPerRoom = false;
        }

        return $this;
    }

    public function getNetPrice(): float
    {
        return $this->includesVat ? $this->price / (1 + $this->vat / 100) : (float) $this->price;
    }

    public function getIsPerRoom(): bool
    {
        return $this->isPerRoom;
    }

    public function setIsPerRoom(bool $isPerRoom): self
    {
        $this->isPerRoom = $isPerRoom;

        return $this;
    }

    public function getRevenueAccount(): ?AccountingAccount
    {
        return $this->revenueAccount;
    }

    public function setRevenueAccount(?AccountingAccount $revenueAccount): self
    {
        $this->revenueAccount = $revenueAccount;

        return $this;
    }
}
