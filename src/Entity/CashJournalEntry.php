<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\CashJournalEntryRepository")
 * @ORM\Table(name="cash_journal_entries")
 **/
class CashJournalEntry
{
    /** 
     * @ORM\Id @ORM\Column(type="integer") @ORM\GeneratedValue 
     **/
    private $id;    

     /** 
     * @ORM\Column(type="decimal", precision=13, scale=2, nullable=true) 
     **/
    private $incomes;

    /** 
     * @ORM\Column(type="decimal", precision=13, scale=2, nullable=true) 
     **/
    private $expenses;

    /** 
     * @ORM\Column(type="decimal", precision=13, scale=2, nullable=true) 
     **/
    private $inventory;

    /** 
     * @ORM\Column(type="string", length=50, nullable=true) 
     **/
    private $counterAccount;
    
    /** 
     * @ORM\Column(type="string", length=50, nullable=true) 
     **/
    private $invoiceNumber;
    
    /** 
     * @ORM\Column(type="integer") 
     **/
    private $documentNumber;
    
    /** 
     * @ORM\Column(type="date") 
     **/
    private $date;
    
    /** 
     * @ORM\Column(type="text", length=255, nullable=true) 
     **/
    private $remark;

    /**
     * @ORM\ManyToOne(targetEntity="CashJournal", inversedBy="cashJournalEntries")
     */
    private $cashJournal;


    /**
     * Get id
     *
     * @return integer
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set incomes
     *
     * @param string $incomes
     *
     * @return CashJournalEntry
     */
    public function setIncomes($incomes)
    {
        $this->incomes = $incomes;

        return $this;
    }

    /**
     * Get incomes
     *
     * @return string
     */
    public function getIncomes()
    {
        return $this->incomes;
    }

    /**
     * Set expenses
     *
     * @param string $expenses
     *
     * @return CashJournalEntry
     */
    public function setExpenses($expenses)
    {
        $this->expenses = $expenses;

        return $this;
    }

    /**
     * Get expenses
     *
     * @return string
     */
    public function getExpenses()
    {
        return $this->expenses;
    }

    /**
     * Set counterAccount
     *
     * @param string $counterAccount
     *
     * @return CashJournalEntry
     */
    public function setCounterAccount($counterAccount)
    {
        $this->counterAccount = $counterAccount;

        return $this;
    }

    /**
     * Get counterAccount
     *
     * @return string
     */
    public function getCounterAccount()
    {
        return $this->counterAccount;
    }

    /**
     * Set invoiceNumber
     *
     * @param string $invoiceNumber
     *
     * @return CashJournalEntry
     */
    public function setInvoiceNumber($invoiceNumber)
    {
        $this->invoiceNumber = $invoiceNumber;

        return $this;
    }

    /**
     * Get invoiceNumber
     *
     * @return string
     */
    public function getInvoiceNumber()
    {
        return $this->invoiceNumber;
    }

    /**
     * Set documentNumber
     *
     * @param integer $documentNumber
     *
     * @return CashJournalEntry
     */
    public function setDocumentNumber($documentNumber)
    {
        $this->documentNumber = $documentNumber;

        return $this;
    }

    /**
     * Get documentNumber
     *
     * @return integer
     */
    public function getDocumentNumber()
    {
        return $this->documentNumber;
    }

    /**
     * Set date
     *
     * @param \DateTime $date
     *
     * @return CashJournalEntry
     */
    public function setDate($date)
    {
        $this->date = $date;

        return $this;
    }

    /**
     * Get date
     *
     * @return \DateTime
     */
    public function getDate()
    {
        return $this->date;
    }

    /**
     * Set remark
     *
     * @param string $remark
     *
     * @return CashJournalEntry
     */
    public function setRemark($remark)
    {
        $this->remark = $remark;

        return $this;
    }

    /**
     * Get remark
     *
     * @return string
     */
    public function getRemark()
    {
        return $this->remark;
    }

    /**
     * Set cashJournal
     *
     * @param \App\Entity\CashJournal $cashJournal
     *
     * @return CashJournalEntry
     */
    public function setCashJournal(\App\Entity\CashJournal $cashJournal = null)
    {
        $this->cashJournal = $cashJournal;

        return $this;
    }

    /**
     * Get cashJournal
     *
     * @return \App\Entity\CashJournal
     */
    public function getCashJournal()
    {
        return $this->cashJournal;
    }

    /**
     * Set inventory
     *
     * @param string $inventory
     *
     * @return CashJournalEntry
     */
    public function setInventory($inventory)
    {
        $this->inventory = $inventory;

        return $this;
    }
    
    /**
     * Get incomes formatted
     *
     * @return string
     */
    public function getIncomesF()
    {
        return number_format($this->incomes, 2, ',', '.');
    }
    
    /**
     * Get expenses formatted
     *
     * @return string
     */
    public function getExpensesF()
    {
        return number_format($this->expenses, 2, ',', '.');
    }

    /**
     * Get inventory formatted
     *
     * @return string
     */
    public function getInventory()
    {
        return $this->inventory;
    }
    
    public function getInventoryF()
    {
        return number_format($this->inventory, 2, ',', '.');
    }
    
    /**
     * Get documentNumber formatted
     *
     * @return integer
     */
    public function getDocumentNumberF()
    {
        return sprintf("%04d", $this->documentNumber);
    }
    
}
