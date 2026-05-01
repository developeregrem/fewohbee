<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BankCsvProfileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BankCsvProfileRepository::class)]
#[ORM\Table(name: 'bank_csv_profiles')]
#[ORM\HasLifecycleCallbacks]
class BankCsvProfile
{
    public const DIRECTION_SIGNED = 'signed';
    public const DIRECTION_SEPARATE_COLUMNS = 'separate_columns';

    public const VALID_DIRECTION_MODES = [
        self::DIRECTION_SIGNED,
        self::DIRECTION_SEPARATE_COLUMNS,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::STRING, length: 3)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 1, max: 3)]
    private string $delimiter = ';';

    #[ORM\Column(type: Types::STRING, length: 1)]
    #[Assert\Length(min: 1, max: 1)]
    private string $enclosure = '"';

    #[ORM\Column(type: Types::STRING, length: 20)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 20)]
    private string $encoding = 'UTF-8';

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    #[Assert\Range(min: 0, max: 50)]
    private int $headerSkip = 0;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $hasHeaderRow = true;

    /**
     * Mapping from logical target field to 0-based column index.
     * Keys: bookDate, valueDate, counterpartyName, counterpartyIban, purpose, amount,
     *       amountDebit, amountCredit, endToEndId, mandateReference, creditorId.
     *
     * @var array<string, int>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $columnMap = [];

    #[ORM\Column(type: Types::STRING, length: 20)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 20)]
    private string $dateFormat = 'd.m.Y';

    #[ORM\Column(type: Types::STRING, length: 1)]
    #[Assert\Length(min: 1, max: 1)]
    private string $amountDecimalSeparator = ',';

    #[ORM\Column(type: Types::STRING, length: 1, nullable: true)]
    #[Assert\Length(max: 1)]
    private ?string $amountThousandsSeparator = '.';

    #[ORM\Column(type: Types::STRING, length: 20)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: self::VALID_DIRECTION_MODES)]
    private string $directionMode = self::DIRECTION_SIGNED;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Assert\Range(min: 0, max: 50)]
    private ?int $ibanSourceLine = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Assert\Range(min: 0, max: 50)]
    private ?int $periodSourceLine = null;

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

    public function getDelimiter(): string
    {
        return $this->delimiter;
    }

    public function setDelimiter(string $delimiter): self
    {
        $this->delimiter = $delimiter;

        return $this;
    }

    public function getEnclosure(): string
    {
        return $this->enclosure;
    }

    public function setEnclosure(string $enclosure): self
    {
        $this->enclosure = $enclosure;

        return $this;
    }

    public function getEncoding(): string
    {
        return $this->encoding;
    }

    public function setEncoding(string $encoding): self
    {
        $this->encoding = $encoding;

        return $this;
    }

    public function getHeaderSkip(): int
    {
        return $this->headerSkip;
    }

    public function setHeaderSkip(int $headerSkip): self
    {
        $this->headerSkip = $headerSkip;

        return $this;
    }

    public function hasHeaderRow(): bool
    {
        return $this->hasHeaderRow;
    }

    public function setHasHeaderRow(bool $hasHeaderRow): self
    {
        $this->hasHeaderRow = $hasHeaderRow;

        return $this;
    }

    /**
     * @return array<string, int>
     */
    public function getColumnMap(): array
    {
        return $this->columnMap;
    }

    /**
     * @param array<string, int> $columnMap
     */
    public function setColumnMap(array $columnMap): self
    {
        $this->columnMap = $columnMap;

        return $this;
    }

    public function getDateFormat(): string
    {
        return $this->dateFormat;
    }

    public function setDateFormat(string $dateFormat): self
    {
        $this->dateFormat = $dateFormat;

        return $this;
    }

    public function getAmountDecimalSeparator(): string
    {
        return $this->amountDecimalSeparator;
    }

    public function setAmountDecimalSeparator(string $separator): self
    {
        $this->amountDecimalSeparator = $separator;

        return $this;
    }

    public function getAmountThousandsSeparator(): ?string
    {
        return $this->amountThousandsSeparator;
    }

    public function setAmountThousandsSeparator(?string $separator): self
    {
        $this->amountThousandsSeparator = $separator;

        return $this;
    }

    public function getDirectionMode(): string
    {
        return $this->directionMode;
    }

    public function setDirectionMode(string $directionMode): self
    {
        $this->directionMode = $directionMode;

        return $this;
    }

    public function getIbanSourceLine(): ?int
    {
        return $this->ibanSourceLine;
    }

    public function setIbanSourceLine(?int $line): self
    {
        $this->ibanSourceLine = $line;

        return $this;
    }

    public function getPeriodSourceLine(): ?int
    {
        return $this->periodSourceLine;
    }

    public function setPeriodSourceLine(?int $line): self
    {
        $this->periodSourceLine = $line;

        return $this;
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
