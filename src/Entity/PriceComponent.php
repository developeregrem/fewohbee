<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\PriceComponentAllocationType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'price_components')]
class PriceComponent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Price::class, inversedBy: 'components')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Price $price = null;

    #[ORM\Column(type: 'string', length: 100)]
    private string $description = '';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private float $vat = 0.0;

    #[ORM\Column(type: 'string', length: 16, enumType: PriceComponentAllocationType::class)]
    private PriceComponentAllocationType $allocationType = PriceComponentAllocationType::PERCENT;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 4)]
    private float $allocationValue = 0.0;

    #[ORM\Column(type: 'boolean')]
    private bool $isRemainder = false;

    #[ORM\Column(type: 'smallint')]
    private int $sortOrder = 0;

    #[ORM\ManyToOne(targetEntity: AccountingAccount::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?AccountingAccount $revenueAccount = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPrice(): ?Price
    {
        return $this->price;
    }

    public function setPrice(?Price $price): self
    {
        $this->price = $price;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getVat(): float
    {
        return $this->vat;
    }

    public function setVat(float $vat): self
    {
        $this->vat = $vat;

        return $this;
    }

    public function getAllocationType(): PriceComponentAllocationType
    {
        return $this->allocationType;
    }

    public function setAllocationType(PriceComponentAllocationType $allocationType): self
    {
        $this->allocationType = $allocationType;

        return $this;
    }

    public function getAllocationValue(): float
    {
        return $this->allocationValue;
    }

    public function setAllocationValue(float $allocationValue): self
    {
        $this->allocationValue = $allocationValue;

        return $this;
    }

    public function isRemainder(): bool
    {
        return $this->isRemainder;
    }

    public function setIsRemainder(bool $isRemainder): self
    {
        $this->isRemainder = $isRemainder;

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
}
