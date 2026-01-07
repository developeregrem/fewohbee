<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'invoice_positions')]
class InvoicePosition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private $id;
    #[ORM\Column(type: 'integer')]
    #[Assert\Positive]
    private $amount;
    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank]
    private $description;
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Assert\PositiveOrZero]
    private $price;
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Assert\PositiveOrZero]
    private float $vat;
    #[ORM\ManyToOne(targetEntity: 'Invoice', inversedBy: 'positions')]
    private $invoice;
    #[ORM\Column(type: 'boolean', nullable: true)]
    private $includesVat;
    #[ORM\Column(type: 'boolean', nullable: true)]
    private $isFlatPrice;

    public function __construct()
    {
        $this->isFlatPrice = false;
        $this->includesVat = false;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getDescription()
    {
        return $this->description;
    }

    public function getPrice()
    {
        return $this->price;
    }

    public function getVat(): float
    {
        return $this->vat;
    }

    public function getInvoice()
    {
        return $this->invoice;
    }

    public function getAmount()
    {
        return $this->amount;
    }

    public function getTotalPriceRaw(): float
    {
        return (float) $this->price * $this->getAmount();
    }

    public function getTotalPrice(): string
    {
        $price = $this->price * $this->getAmount();

        return number_format((float) $price, 2, ',', '.');
    }

    public function getPriceFormated(): string
    {
        return number_format((float) $this->price, 2, ',', '.');
    }

    public function setId($id): void
    {
        $this->id = $id;
    }

    public function setDescription($description): void
    {
        $this->description = $description;
    }

    public function setPrice($price): void
    {
        $this->price = $price;
    }

    public function setVat($vat): void
    {
        $this->vat = (float) $vat;
    }

    public function setInvoice($invoice): void
    {
        $this->invoice = $invoice;
    }

    public function setAmount($amount): void
    {
        $this->amount = $amount;
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

        return $this;
    }

    public function getNetPrice(): float
    {
        return $this->includesVat ? $this->price / (1 + $this->vat / 100) : (float) $this->price;
    }
}
