<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'invoice_appartments')]
class InvoiceAppartment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private $id;
    #[ORM\Column(type: 'string', length: 10)]
    #[Assert\NotBlank]
    private $number;
    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank]
    private $description;
    #[ORM\Column(type: 'smallint')]
    #[Assert\Positive]
    private $beds;
    #[ORM\Column(type: 'smallint')]
    #[Assert\Positive]
    private $persons;
    #[ORM\Column(name: 'start_date', type: 'date')]
    #[Assert\NotNull]
    private $startDate;
    #[ORM\Column(name: 'end_date', type: 'date')]
    #[Assert\NotNull]
    private $endDate;
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Assert\PositiveOrZero]
    private $price;
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Assert\PositiveOrZero]
    private float $vat;
    #[ORM\ManyToOne(targetEntity: 'Invoice', inversedBy: 'appartments')]
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

    public function getNumber()
    {
        return $this->number;
    }

    public function getBeds()
    {
        return $this->beds;
    }

    public function getPersons()
    {
        return $this->persons;
    }

    public function getStartDate(): \DateTime
    {
        return $this->startDate;
    }

    public function getEndDate(): \DateTime
    {
        return $this->endDate;
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

    public function getDescription()
    {
        return $this->description;
    }

    public function getAmount(): int
    {
        if ($this->isFlatPrice) {
            return 1;
        }
        // else
        $interval = $this->startDate->diff($this->endDate);

        return (int) $interval->format('%a');
    }

    public function getTotalPriceRaw(): float
    {
        return $this->isFlatPrice ? (float) $this->price : (float) $this->price * $this->getAmount();
    }

    public function getTotalPrice(): string
    {
        $price = ($this->isFlatPrice ? $this->price : $this->price * $this->getAmount());

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

    public function setNumber($number): void
    {
        $this->number = $number;
    }

    public function setBeds($beds): void
    {
        $this->beds = $beds;
    }

    public function setPersons($persons): void
    {
        $this->persons = $persons;
    }

    public function setStartDate($startDate): void
    {
        $this->startDate = $startDate;
    }

    public function setEndDate($endDate): void
    {
        $this->endDate = $endDate;
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

    public function setDescription($description): void
    {
        $this->description = $description;
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
