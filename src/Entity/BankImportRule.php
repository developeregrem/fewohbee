<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BankImportRuleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BankImportRuleRepository::class)]
#[ORM\Table(name: 'bank_import_rules')]
#[ORM\Index(name: 'idx_bank_import_rule_enabled', columns: ['is_enabled'])]
#[ORM\Index(name: 'idx_bank_import_rule_priority', columns: ['priority'])]
#[ORM\HasLifecycleCallbacks]
class BankImportRule
{
    public const ACTION_MODE_ASSIGN = 'assign';
    public const ACTION_MODE_SPLIT = 'split';
    public const ACTION_MODE_IGNORE = 'ignore';

    public const CONDITION_FIELD_COUNTERPARTY_NAME = 'counterpartyName';
    public const CONDITION_FIELD_COUNTERPARTY_IBAN = 'counterpartyIban';
    public const CONDITION_FIELD_PURPOSE = 'purpose';
    public const CONDITION_FIELD_AMOUNT = 'amount';
    public const CONDITION_FIELD_DIRECTION = 'direction';

    public const CONDITION_OP_CONTAINS = 'contains';
    public const CONDITION_OP_NOT_CONTAINS = 'not_contains';
    public const CONDITION_OP_EQUALS = 'equals';
    public const CONDITION_OP_REGEX = 'regex';
    public const CONDITION_OP_GT = 'gt';
    public const CONDITION_OP_LT = 'lt';
    public const CONDITION_OP_BETWEEN = 'between';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 150)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 150)]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * When set, this rule only applies to imports for this specific bank account.
     * When null, the rule is global and applies to all imports.
     */
    #[ORM\ManyToOne(targetEntity: AccountingAccount::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?AccountingAccount $bankAccount = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $priority = 0;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isEnabled = true;

    /**
     * Array of condition objects. All conditions must match (AND logic).
     * Each element: { field: string, operator: string, value: mixed }
     *
     * @var list<array{field: string, operator: string, value: mixed}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $conditions = [];

    /**
     * Action to perform when all conditions match.
     * Mode "assign": { mode: "assign", debitAccountId: int|null, creditAccountId: int|null, taxRateId: int|null, remarkTemplate: string|null }
     * Mode "split":  { mode: "split", splits: [{ amount: float|null, percent: float|null, remainder: bool, amountSource: "purpose_marker"|null, marker: string|null, debitAccountId: int, creditAccountId: int, taxRateId: int|null, remarkTemplate: string|null }] }
     * Mode "ignore": { mode: "ignore" }
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $action = ['mode' => self::ACTION_MODE_IGNORE];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getBankAccount(): ?AccountingAccount
    {
        return $this->bankAccount;
    }

    public function setBankAccount(?AccountingAccount $bankAccount): self
    {
        $this->bankAccount = $bankAccount;

        return $this;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->isEnabled;
    }

    public function setIsEnabled(bool $isEnabled): self
    {
        $this->isEnabled = $isEnabled;

        return $this;
    }

    /**
     * @return list<array{field: string, operator: string, value: mixed}>
     */
    public function getConditions(): array
    {
        return $this->conditions;
    }

    /**
     * @param list<array{field: string, operator: string, value: mixed}> $conditions
     */
    public function setConditions(array $conditions): self
    {
        $this->conditions = $conditions;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAction(): array
    {
        return $this->action;
    }

    /**
     * @param array<string, mixed> $action
     */
    public function setAction(array $action): self
    {
        $this->action = $action;

        return $this;
    }

    public function getActionMode(): string
    {
        return $this->action['mode'] ?? self::ACTION_MODE_IGNORE;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
