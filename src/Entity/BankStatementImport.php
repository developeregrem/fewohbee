<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BankStatementImportRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BankStatementImportRepository::class)]
#[ORM\Table(name: 'bank_statement_imports')]
#[ORM\Index(name: 'idx_bank_statement_import_bank_account', columns: ['bank_account_id'])]
#[ORM\Index(name: 'idx_bank_statement_import_status', columns: ['status'])]
class BankStatementImport
{
    public const STATUS_COMMITTED = 'committed';
    public const STATUS_DISCARDED = 'discarded';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AccountingAccount::class)]
    #[ORM\JoinColumn(nullable: false)]
    private AccountingAccount $bankAccount;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTime $periodFrom = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTime $periodTo = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $lineCountTotal = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $lineCountCommitted = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $lineCountIgnored = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $lineCountDuplicate = 0;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $fileFormat = 'csv_generic';

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status = self::STATUS_COMMITTED;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $committedAt = null;

    public function __construct(AccountingAccount $bankAccount)
    {
        $this->bankAccount = $bankAccount;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBankAccount(): AccountingAccount
    {
        return $this->bankAccount;
    }

    public function setBankAccount(AccountingAccount $bankAccount): self
    {
        $this->bankAccount = $bankAccount;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): self
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getPeriodFrom(): ?\DateTime
    {
        return $this->periodFrom;
    }

    public function setPeriodFrom(?\DateTime $periodFrom): self
    {
        $this->periodFrom = $periodFrom;

        return $this;
    }

    public function getPeriodTo(): ?\DateTime
    {
        return $this->periodTo;
    }

    public function setPeriodTo(?\DateTime $periodTo): self
    {
        $this->periodTo = $periodTo;

        return $this;
    }

    public function getLineCountTotal(): int
    {
        return $this->lineCountTotal;
    }

    public function setLineCountTotal(int $count): self
    {
        $this->lineCountTotal = $count;

        return $this;
    }

    public function getLineCountCommitted(): int
    {
        return $this->lineCountCommitted;
    }

    public function setLineCountCommitted(int $count): self
    {
        $this->lineCountCommitted = $count;

        return $this;
    }

    public function getLineCountIgnored(): int
    {
        return $this->lineCountIgnored;
    }

    public function setLineCountIgnored(int $count): self
    {
        $this->lineCountIgnored = $count;

        return $this;
    }

    public function getLineCountDuplicate(): int
    {
        return $this->lineCountDuplicate;
    }

    public function setLineCountDuplicate(int $count): self
    {
        $this->lineCountDuplicate = $count;

        return $this;
    }

    public function getFileFormat(): string
    {
        return $this->fileFormat;
    }

    public function setFileFormat(string $fileFormat): self
    {
        $this->fileFormat = $fileFormat;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCommittedAt(): ?\DateTimeImmutable
    {
        return $this->committedAt;
    }

    public function setCommittedAt(?\DateTimeImmutable $committedAt): self
    {
        $this->committedAt = $committedAt;

        return $this;
    }
}
