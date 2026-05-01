<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BankImportFingerprintRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BankImportFingerprintRepository::class)]
#[ORM\Table(name: 'bank_import_fingerprints')]
#[ORM\UniqueConstraint(name: 'uq_bank_fingerprint', columns: ['bank_account_id', 'raw_hash'])]
class BankImportFingerprint
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AccountingAccount::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AccountingAccount $bankAccount;

    /**
     * SHA-256 of: bookDate|valueDate|amount|counterpartyIban|normalizedPurpose|endToEndId
     * Not reversible — only used for duplicate detection.
     */
    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $rawHash;

    /**
     * The journal entry created for this line, if any.
     * Null when the line was deliberately ignored at commit time.
     */
    #[ORM\ManyToOne(targetEntity: BookingEntry::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?BookingEntry $bookingEntry = null;

    #[ORM\ManyToOne(targetEntity: BankStatementImport::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?BankStatementImport $statementImport = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $committedAt;

    public function __construct(AccountingAccount $bankAccount, string $rawHash)
    {
        $this->bankAccount = $bankAccount;
        $this->rawHash = $rawHash;
        $this->committedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBankAccount(): AccountingAccount
    {
        return $this->bankAccount;
    }

    public function getRawHash(): string
    {
        return $this->rawHash;
    }

    public function getBookingEntry(): ?BookingEntry
    {
        return $this->bookingEntry;
    }

    public function setBookingEntry(?BookingEntry $bookingEntry): self
    {
        $this->bookingEntry = $bookingEntry;

        return $this;
    }

    public function getStatementImport(): ?BankStatementImport
    {
        return $this->statementImport;
    }

    public function setStatementImport(?BankStatementImport $statementImport): self
    {
        $this->statementImport = $statementImport;

        return $this;
    }

    public function getCommittedAt(): \DateTimeImmutable
    {
        return $this->committedAt;
    }
}
