<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity
 * @ORM\Table(name="invoice_appartments")
 **/

class InvoiceAppartment
{
    /** 
     * @ORM\Id @ORM\Column(type="bigint") 
     * @ORM\GeneratedValue 
     */
    private $id;

    /** 
     * @ORM\Column(type="string", length=10) 
     * @Assert\NotBlank
     */
    private $number;

    /** 
     * @ORM\Column(type="string", length=255) 
     * @Assert\NotBlank
     */
    private $description;

    /** 
     * @ORM\Column(type="smallint") 
     * @Assert\Positive
     */
    private $beds;

    /** 
     * @ORM\Column(type="smallint") 
     * @Assert\Positive
     */
    private $persons;

    /** 
     * @ORM\Column(name="start_date", type="date") 
     * @Assert\NotNull
     */
    private $startDate;

    /** 
     * @ORM\Column(name="end_date", type="date") 
     * @Assert\NotNull
     */
    private $endDate;

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
     * @ORM\ManyToOne(targetEntity="Invoice", inversedBy="appartments")
     */
    private $invoice;

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

    public function getStartDate()
    {
        return $this->startDate;
    }

    public function getEndDate()
    {
        return $this->endDate;
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

    public function getDescription()
    {
        return $this->description;
    }

    public function getAmount()
    {
        $interval = $this->startDate->diff($this->endDate);
        return $interval->format('%a');
    }
    
    public function getTotalPrice()
    {
        return number_format($this->price * $this->getAmount(), 2, ',', '.');
    }
    
    public function getPriceFormated()
    {
        return number_format($this->price, 2, ',', '.');
    }

    public function setId($id)
    {
        $this->id = $id;
    }

    public function setNumber($number)
    {
        $this->number = $number;
    }

    public function setBeds($beds)
    {
        $this->beds = $beds;
    }

    public function setPersons($persons)
    {
        $this->persons = $persons;
    }

    public function setStartDate($startDate)
    {
        $this->startDate = $startDate;
    }

    public function setEndDate($endDate)
    {
        $this->endDate = $endDate;
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

    public function setDescription($description)
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
}
