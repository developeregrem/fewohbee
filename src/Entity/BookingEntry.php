<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BookingEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BookingEntryRepository::class)]
#[ORM\Table(name: 'booking_entries')]
#[ORM\Index(name: 'idx_booking_entry_split_group', columns: ['split_group_uuid'])]
class BookingEntry
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_WORKFLOW = 'workflow';
    public const SOURCE_MIGRATION = 'migration';
    public const SOURCE_OPENING_BALANCE = 'opening_balance';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BookingBatch::class, inversedBy: 'entries')]
    #[ORM\JoinColumn(nullable: false)]
    private BookingBatch $bookingBatch;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private \DateTime $date;

    #[ORM\Column(type: Types::INTEGER)]
    private int $documentNumber = 0;

    #[ORM\Column(type: Types::DECIMAL, precision: 13, scale: 2)]
    private string $amount = '0.00';

    #[ORM\ManyToOne(targetEntity: AccountingAccount::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?AccountingAccount $debitAccount = null;

    #[ORM\ManyToOne(targetEntity: AccountingAccount::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?AccountingAccount $creditAccount = null;

    #[ORM\ManyToOne(targetEntity: TaxRate::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?TaxRate $taxRate = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $invoiceNumber = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $invoiceId = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $remark = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $counterAccountLegacy = null;

    #[ORM\Column(type: Types::STRING, length: 30, nullable: true)]
    private ?string $sourceType = null;

    /**
     * Groups entries that originate from the same underlying document, e.g. a bank
     * statement line split across multiple debit accounts. Entries with the same
     * UUID are rendered together in the journal view.
     */
    #[ORM\Column(type: Types::STRING, length: 36, nullable: true)]
    private ?string $splitGroupUuid = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->date = new \DateTime();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBookingBatch(): BookingBatch
    {
        return $this->bookingBatch;
    }

    public function setBookingBatch(BookingBatch $bookingBatch): self
    {
        $this->bookingBatch = $bookingBatch;

        return $this;
    }

    public function getDate(): \DateTime
    {
        return $this->date;
    }

    public function setDate(\DateTime $date): self
    {
        $this->date = $date;

        return $this;
    }

    public function getDocumentNumber(): int
    {
        return $this->documentNumber;
    }

    public function setDocumentNumber(int $documentNumber): self
    {
        $this->documentNumber = $documentNumber;

        return $this;
    }

    public function getDocumentNumberF(): string
    {
        return sprintf('%04d', $this->documentNumber);
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getAmountFloat(): float
    {
        return (float) $this->amount;
    }

    public function getAmountF(): string
    {
        return number_format((float) $this->amount, 2, ',', '.');
    }

    public function setAmount(mixed $amount): self
    {
        $this->amount = number_format((float) $amount, 2, '.', '');

        return $this;
    }

    public function getDebitAccount(): ?AccountingAccount
    {
        return $this->debitAccount;
    }

    public function setDebitAccount(?AccountingAccount $debitAccount): self
    {
        $this->debitAccount = $debitAccount;

        return $this;
    }

    public function getCreditAccount(): ?AccountingAccount
    {
        return $this->creditAccount;
    }

    public function setCreditAccount(?AccountingAccount $creditAccount): self
    {
        $this->creditAccount = $creditAccount;

        if ($creditAccount?->isOpeningBalanceAccount() ?? false) {
            $this->sourceType = self::SOURCE_OPENING_BALANCE;
        } elseif (self::SOURCE_OPENING_BALANCE === $this->sourceType) {
            $this->sourceType = self::SOURCE_MANUAL;
        }

        return $this;
    }

    public function getTaxRate(): ?TaxRate
    {
        return $this->taxRate;
    }

    public function setTaxRate(?TaxRate $taxRate): self
    {
        $this->taxRate = $taxRate;

        return $this;
    }

    public function getInvoiceNumber(): ?string
    {
        return $this->invoiceNumber;
    }

    public function setInvoiceNumber(?string $invoiceNumber): self
    {
        $this->invoiceNumber = $invoiceNumber;

        return $this;
    }

    public function getInvoiceId(): ?int
    {
        return $this->invoiceId;
    }

    public function setInvoiceId(?int $invoiceId): self
    {
        $this->invoiceId = $invoiceId;

        return $this;
    }

    public function getRemark(): ?string
    {
        return $this->remark;
    }

    public function setRemark(?string $remark): self
    {
        $this->remark = $remark;

        return $this;
    }

    public function getCounterAccountLegacy(): ?string
    {
        return $this->counterAccountLegacy;
    }

    public function setCounterAccountLegacy(?string $counterAccountLegacy): self
    {
        $this->counterAccountLegacy = $counterAccountLegacy;

        return $this;
    }

    public function getSourceType(): ?string
    {
        return $this->sourceType;
    }

    public function setSourceType(?string $sourceType): self
    {
        $this->sourceType = $sourceType;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSplitGroupUuid(): ?string
    {
        return $this->splitGroupUuid;
    }

    public function setSplitGroupUuid(?string $splitGroupUuid): self
    {
        $this->splitGroupUuid = $splitGroupUuid;

        return $this;
    }

    public function isOpeningBalance(): bool
    {
        return self::SOURCE_OPENING_BALANCE === $this->sourceType;
    }

    // ── Kassenbuch template compatibility getters ────────────────

    /**
     * For Kassenbuch form: returns amount if this is an income (debit=cash), else 0.
     */
    public function getIncomes(): float
    {
        if ($this->debitAccount?->isCashAccount()) {
            return (float) $this->amount;
        }

        return 0.0;
    }

    /**
     * For Kassenbuch form: returns amount if this is an expense (credit=cash), else 0.
     */
    public function getExpenses(): float
    {
        if ($this->creditAccount?->isCashAccount()) {
            return (float) $this->amount;
        }

        return 0.0;
    }

    /**
     * For Kassenbuch: returns the counter-account name (the non-cash side),
     * or the legacy free-text value.
     */
    public function getCounterAccount(): ?string
    {
        if ($this->debitAccount?->isCashAccount() && null !== $this->creditAccount) {
            return $this->creditAccount->getLabel();
        }
        if ($this->creditAccount?->isCashAccount() && null !== $this->debitAccount) {
            return $this->debitAccount->getLabel();
        }

        return $this->counterAccountLegacy;
    }
}
