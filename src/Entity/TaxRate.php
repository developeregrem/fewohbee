<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TaxRateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TaxRateRepository::class)]
#[ORM\Table(name: 'tax_rates')]
class TaxRate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    private string $name = '';

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    #[Assert\NotNull]
    #[Assert\GreaterThanOrEqual(0)]
    private string $rate = '0.00';

    #[ORM\Column(name: 'datev_output_bu_key', type: Types::STRING, length: 4, nullable: true)]
    #[Assert\Length(max: 4)]
    private ?string $datevOutputBuKey = null;

    #[ORM\Column(name: 'datev_input_bu_key', type: Types::STRING, length: 4, nullable: true)]
    #[Assert\Length(max: 4)]
    private ?string $datevInputBuKey = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTime $validFrom = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTime $validTo = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isDefault = false;

    #[ORM\ManyToOne(targetEntity: AccountingAccount::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?AccountingAccount $revenueAccount = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $sortOrder = 0;

    /**
     * Origin preset (skr03, skr04, ekr_at, kmu_ch). NULL for user-created tax rates.
     */
    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $chartPreset = null;

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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getRate(): string
    {
        return $this->rate;
    }

    public function getRateFloat(): float
    {
        return (float) $this->rate;
    }

    public function setRate(string $rate): self
    {
        $this->rate = $rate;

        return $this;
    }

    public function getDatevOutputBuKey(): ?string
    {
        return $this->datevOutputBuKey;
    }

    public function setDatevOutputBuKey(?string $datevOutputBuKey): self
    {
        $this->datevOutputBuKey = $datevOutputBuKey;

        return $this;
    }

    public function getDatevInputBuKey(): ?string
    {
        return $this->datevInputBuKey;
    }

    public function setDatevInputBuKey(?string $datevInputBuKey): self
    {
        $this->datevInputBuKey = $datevInputBuKey;

        return $this;
    }

    public function getValidFrom(): ?\DateTime
    {
        return $this->validFrom;
    }

    public function setValidFrom(?\DateTime $validFrom): self
    {
        $this->validFrom = $validFrom;

        return $this;
    }

    public function getValidTo(): ?\DateTime
    {
        return $this->validTo;
    }

    public function setValidTo(?\DateTime $validTo): self
    {
        $this->validTo = $validTo;

        return $this;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function setIsDefault(bool $isDefault): self
    {
        $this->isDefault = $isDefault;

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

    public function getRevenueAccount(): ?AccountingAccount
    {
        return $this->revenueAccount;
    }

    public function setRevenueAccount(?AccountingAccount $revenueAccount): self
    {
        $this->revenueAccount = $revenueAccount;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getChartPreset(): ?string
    {
        return $this->chartPreset;
    }

    public function setChartPreset(?string $chartPreset): self
    {
        $this->chartPreset = $chartPreset;

        return $this;
    }

    /**
     * Check if this tax rate is valid for a given date.
     */
    public function isValidAt(\DateTimeInterface $date): bool
    {
        if (null !== $this->validFrom && $date < $this->validFrom) {
            return false;
        }

        if (null !== $this->validTo && $date > $this->validTo) {
            return false;
        }

        return true;
    }

    /**
     * Display label for dropdowns: "19% USt".
     */
    public function getLabel(): string
    {
        return $this->name;
    }

    public function __toString(): string
    {
        return $this->getLabel();
    }
}
