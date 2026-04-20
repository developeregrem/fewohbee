<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AccountingAccountRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: AccountingAccountRepository::class)]
#[ORM\Table(name: 'accounting_accounts')]
#[UniqueEntity('accountNumber')]
class AccountingAccount
{
    public const TYPE_ASSET = 'asset';
    public const TYPE_LIABILITY = 'liability';
    public const TYPE_REVENUE = 'revenue';
    public const TYPE_EXPENSE = 'expense';

    public const VALID_TYPES = [
        self::TYPE_ASSET,
        self::TYPE_LIABILITY,
        self::TYPE_REVENUE,
        self::TYPE_EXPENSE,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 10, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 10)]
    private string $accountNumber = '';

    #[ORM\Column(type: Types::STRING, length: 150)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 150)]
    private string $name = '';

    #[ORM\Column(type: Types::STRING, length: 20)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: self::VALID_TYPES)]
    private string $type = self::TYPE_ASSET;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isCashAccount = false;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isBankAccount = false;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isOpeningBalanceAccount = false;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isAutoAccount = false;

    #[ORM\Column(name: 'datev_sachverhalt_l_u_l', type: Types::SMALLINT, nullable: true)]
    #[Assert\Range(min: 0, max: 99)]
    private ?int $datevSachverhaltLuL = null;

    #[ORM\Column(name: 'datev_funktionsergaenzung_l_u_l', type: Types::SMALLINT, nullable: true)]
    #[Assert\Range(min: 0, max: 999)]
    private ?int $datevFunktionsergaenzungLuL = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isSystemDefault = false;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccountNumber(): string
    {
        return $this->accountNumber;
    }

    public function setAccountNumber(string $accountNumber): self
    {
        $this->accountNumber = $accountNumber;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function isCashAccount(): bool
    {
        return $this->isCashAccount;
    }

    public function setIsCashAccount(bool $isCashAccount): self
    {
        $this->isCashAccount = $isCashAccount;

        return $this;
    }

    public function isBankAccount(): bool
    {
        return $this->isBankAccount;
    }

    public function setIsBankAccount(bool $isBankAccount): self
    {
        $this->isBankAccount = $isBankAccount;

        return $this;
    }

    public function isOpeningBalanceAccount(): bool
    {
        return $this->isOpeningBalanceAccount;
    }

    public function setIsOpeningBalanceAccount(bool $isOpeningBalanceAccount): self
    {
        $this->isOpeningBalanceAccount = $isOpeningBalanceAccount;

        return $this;
    }

    public function isAutoAccount(): bool
    {
        return $this->isAutoAccount;
    }

    public function setIsAutoAccount(bool $isAutoAccount): self
    {
        $this->isAutoAccount = $isAutoAccount;

        return $this;
    }

    public function getDatevSachverhaltLuL(): ?int
    {
        return $this->datevSachverhaltLuL;
    }

    public function setDatevSachverhaltLuL(?int $datevSachverhaltLuL): self
    {
        $this->datevSachverhaltLuL = $datevSachverhaltLuL;

        return $this;
    }

    public function getDatevFunktionsergaenzungLuL(): ?int
    {
        return $this->datevFunktionsergaenzungLuL;
    }

    public function setDatevFunktionsergaenzungLuL(?int $datevFunktionsergaenzungLuL): self
    {
        $this->datevFunktionsergaenzungLuL = $datevFunktionsergaenzungLuL;

        return $this;
    }

    public function isSystemDefault(): bool
    {
        return $this->isSystemDefault;
    }

    public function setIsSystemDefault(bool $isSystemDefault): self
    {
        $this->isSystemDefault = $isSystemDefault;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Display label for dropdowns: "1000 - Kasse".
     */
    public function getLabel(): string
    {
        return $this->accountNumber.' - '.$this->name;
    }

    public function __toString(): string
    {
        return $this->getLabel();
    }
}
