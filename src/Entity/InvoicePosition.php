<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity @ORM\Table(name="invoice_positions")
 **/

class InvoicePosition
{
    /** @ORM\Id @ORM\Column(type="bigint") @ORM\GeneratedValue * */
    private $id;

    /** @ORM\Column(type="integer") * */
    private $amount;

    /** @ORM\Column(type="string", length=255) * */
    private $description;

    /** @ORM\Column(type="decimal", scale=2) * */
    private $price;

    /** @ORM\Column(type="decimal", scale=2) * */
    private $vat;

    /**
     * @ORM\ManyToOne(targetEntity="Invoice", inversedBy="positions")
     */
    private $invoice;

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
}
