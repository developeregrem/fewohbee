<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity @ORM\Table(name="invoice_positions")
 **/

class InvoicePosition
{
    /** @ORM\Id @ORM\Column(type="bigint") @ORM\GeneratedValue * */
    private $id;

    /** 
     * @ORM\Column(type="integer")
     * @Assert\Positive
     */
    private $amount;

    /** 
     * @ORM\Column(type="string", length=255) 
     * @Assert\NotBlank
     */
    private $description;

    /** 
     * @ORM\Column(type="decimal", scale=2) 
     * @Assert\PositiveOrZero
     */
    private $price;

    /** 
     * @ORM\Column(type="decimal", scale=2) 
     * @Assert\PositiveOrZero
     */
    private $vat;

    /**
     * @ORM\ManyToOne(targetEntity="Invoice", inversedBy="positions")
     */
    private $invoice;

    /**
     * @ORM\Column(type="boolean")
     */
    /**
     * @ORM\Column(type="boolean", nullable=true)
     */
    private $includesVat;

    /**
     * @ORM\Column(type="boolean", nullable=true)
     */
    private $isFlatPrice;
    
    public function __construct() {
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

    public function getVat()
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
    
    public function getTotalPriceRaw()
    {
        $price = $this->price * $this->getAmount();
        return $price;
    }
    
    public function getTotalPrice()
    {
        $price = $this->price * $this->getAmount();
        return number_format($price, 2, ',', '.');
    }
    
    public function getPriceFormated()
    {
        return number_format($this->price, 2, ',', '.');
    }

    public function setId($id)
    {
        $this->id = $id;
    }

    public function setDescription($description)
    {
        $this->description = $description;
    }

    public function setPrice($price)
    {
        $this->price = $price;
    }

    public function setVat($vat)
    {
        $this->vat = $vat;
    }

    public function setInvoice($invoice)
    {
        $this->invoice = $invoice;
    }

    public function setAmount($amount)
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
}
