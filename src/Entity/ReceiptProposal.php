<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\ReceiptProposalStatus;
use App\Repository\ReceiptProposalRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A receipt an AI assistant read and handed in for booking, waiting for a person to decide.
 *
 * The assistant only sees the receipt it was given: it submits what it read (supplier, date,
 * total, the parts per VAT rate with suggested accounts) and learns nothing about the journal
 * in return. Finding the payment and booking happen in FewohBee, after a person checked the
 * proposal (ReceiptProposalService).
 *
 * Everything the assistant sent is untrusted input and only ever shown escaped.
 */
#[ORM\Entity(repositoryClass: ReceiptProposalRepository::class)]
#[ORM\Table(name: 'receipt_proposals')]
#[ORM\Index(name: 'idx_receipt_proposal_status', columns: ['status'])]
class ReceiptProposal
{
    public const PAYMENT_BANK = 'bank';
    public const PAYMENT_CASH = 'cash';
    public const PAYMENT_UNKNOWN = 'unknown';
    public const PAYMENTS = [self::PAYMENT_BANK, self::PAYMENT_CASH, self::PAYMENT_UNKNOWN];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: ReceiptProposalStatus::class, options: ['default' => 'open'])]
    private ReceiptProposalStatus $status = ReceiptProposalStatus::OPEN;

    #[ORM\Column(type: Types::STRING, length: 150)]
    private string $supplier;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $receiptDate;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $receiptNumber;

    /** Gross total as decimal string with two places, like BookingEntry::$amount. */
    #[ORM\Column(type: Types::DECIMAL, precision: 13, scale: 2)]
    private string $total;

    /** How the receipt says it was paid; decides where FewohBee looks for the payment. */
    #[ORM\Column(type: Types::STRING, length: 10, options: ['default' => 'unknown'])]
    private string $payment;

    /**
     * The parts as read: gross amount, VAT rate in percent and the suggested account number.
     *
     * @var list<array{text: ?string, amount: string, taxRate: ?string, accountNumber: ?string}>
     */
    #[ORM\Column(name: 'receipt_lines', type: Types::JSON)]
    private array $lines;

    /** What the assistant wants the person to know, e.g. an unreadable position. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $submittedBy;

    /** Prefix of the access token that submitted it, to tell several assistants apart. */
    #[ORM\Column(type: Types::STRING, length: 12, nullable: true)]
    private ?string $tokenPrefix;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $decidedBy = null;

    /**
     * Journal entries the proposal was booked as.
     *
     * @var list<int>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $bookedEntryIds = null;

    /**
     * @param list<array{text: ?string, amount: string, taxRate: ?string, accountNumber: ?string}> $lines
     */
    public function __construct(
        string $supplier,
        \DateTimeImmutable $receiptDate,
        ?string $receiptNumber,
        string $total,
        string $payment,
        array $lines,
        ?string $note,
        ?User $submittedBy,
        ?string $tokenPrefix,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->supplier = $supplier;
        $this->receiptDate = $receiptDate;
        $this->receiptNumber = $receiptNumber;
        $this->total = $total;
        $this->payment = $payment;
        $this->lines = $lines;
        $this->note = $note;
        $this->submittedBy = $submittedBy;
        $this->tokenPrefix = $tokenPrefix;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStatus(): ReceiptProposalStatus
    {
        return $this->status;
    }

    public function isOpen(): bool
    {
        return ReceiptProposalStatus::OPEN === $this->status;
    }

    public function getSupplier(): string
    {
        return $this->supplier;
    }

    public function getReceiptDate(): \DateTimeImmutable
    {
        return $this->receiptDate;
    }

    public function getReceiptNumber(): ?string
    {
        return $this->receiptNumber;
    }

    public function getTotal(): string
    {
        return $this->total;
    }

    public function getPayment(): string
    {
        return $this->payment;
    }

    /** @return list<array{text: ?string, amount: string, taxRate: ?string, accountNumber: ?string}> */
    public function getLines(): array
    {
        return $this->lines;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getSubmittedBy(): ?User
    {
        return $this->submittedBy;
    }

    public function getTokenPrefix(): ?string
    {
        return $this->tokenPrefix;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function getDecidedBy(): ?User
    {
        return $this->decidedBy;
    }

    /** @return list<int> */
    public function getBookedEntryIds(): array
    {
        return $this->bookedEntryIds ?? [];
    }

    /** @param list<int> $entryIds */
    public function markBooked(array $entryIds, ?User $user, \DateTimeImmutable $now): void
    {
        $this->status = ReceiptProposalStatus::BOOKED;
        $this->bookedEntryIds = $entryIds;
        $this->decidedBy = $user;
        $this->decidedAt = $now;
    }

    public function discard(?User $user, \DateTimeImmutable $now): void
    {
        $this->status = ReceiptProposalStatus::DISCARDED;
        $this->decidedBy = $user;
        $this->decidedAt = $now;
    }
}
