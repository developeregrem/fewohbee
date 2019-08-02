<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="invoice_appartments")
 **/

class InvoiceAppartment
{
    /** @ORM\Id @ORM\Column(type="bigint") @ORM\GeneratedValue * */
    private $id;

    /** @ORM\Column(type="string", length=10) * */
    private $number;

    /** @ORM\Column(type="string", length=255) * */
    private $description;

    /** @ORM\Column(type="smallint") * */
    private $beds;

    /** @ORM\Column(type="smallint") * */
    private $persons;

    /** @ORM\Column(name="start_date", type="date") * */
    private $startDate;

    /** @ORM\Column(name="end_date", type="date") * */
    private $endDate;

    /** @ORM\Column(type="decimal", scale=2) * */
    private $price;

    /** @ORM\Column(type="decimal", scale=2) * */
    private $vat;

    /**
     * @ORM\ManyToOne(targetEntity="Invoice", inversedBy="appartments")
     */
    private $invoice;

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
}
