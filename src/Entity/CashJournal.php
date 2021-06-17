<?php
namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\CashJournalRepository")
 * @ORM\Table(name="cash_journal")
 **/
class CashJournal
{
    /** 
     * @ORM\Id @ORM\Column(type="integer") @ORM\GeneratedValue 
     **/
    private $id;

    /** 
     * @ORM\Column(type="smallint", options={"unsigned"=true}) 
     **/
    private $cashYear;

     /** 
     * @ORM\Column(type="smallint", options={"unsigned"=true}) 
     **/
    private $cashMonth;

    /** 
     * @ORM\Column(type="decimal", precision=13, scale=2, nullable=false) 
     **/
    private $cashStart;

    /** 
     * @ORM\Column(type="decimal", precision=13, scale=2, nullable=true) 
     **/
    private $cashEnd;

    /** 
     * @ORM\Column(type="boolean", ) 
     **/
    private $isClosed;
    
     /** 
      * @ORM\Column(type="boolean", ) 
      **/
    private $isBooked;

    /**
     * @ORM\OneToMany(targetEntity="CashJournalEntry", mappedBy="cashJournal", cascade={"remove"})
     * @ORM\OrderBy({"date" = "ASC"})
     */
    private $cashJournalEntries;


    public function __construct()
    {
        $this->cashJournalEntries = new ArrayCollection();
        $this->isClosed = false;
        $this->isBooked = false;
    }

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
     * Set cashYear
     *
     * @param string $cashYear
     *
     * @return CashJournal
     */
    public function setCashYear($cashYear)
    {
        $this->cashYear = $cashYear;

        return $this;
    }

    /**
     * Get cashYear
     *
     * @return string
     */
    public function getCashYear()
    {
        return $this->cashYear;
    }

    /**
     * Set cashMonth
     *
     * @param string $cashMonth
     *
     * @return CashJournal
     */
    public function setCashMonth($cashMonth)
    {
        $this->cashMonth = $cashMonth;

        return $this;
    }

    /**
     * Get cashMonth
     *
     * @return string
     */
    public function getCashMonth()
    {
        return $this->cashMonth;
    }

    /**
     * Set cashStart
     *
     * @param string $cashStart
     *
     * @return CashJournal
     */
    public function setCashStart($cashStart)
    {
        $this->cashStart = $cashStart;

        return $this;
    }

    /**
     * Get cashStart
     *
     * @return string
     */
    public function getCashStart()
    {
        return $this->cashStart;
    }
    
    /**
     * Get cashStart
     *
     * @return string
     */
    public function getCashStartF()
    {
        return number_format($this->cashStart, 2, ',', '.');
    }

    /**
     * Set cashEnd
     *
     * @param string $cashEnd
     *
     * @return CashJournal
     */
    public function setCashEnd($cashEnd)
    {
        $this->cashEnd = $cashEnd;

        return $this;
    }

    /**
     * Get cashEnd
     *
     * @return string
     */
    public function getCashEnd()
    {
        return $this->cashEnd;
    }
    
    /**
     * Get cashStart
     *
     * @return string
     */
    public function getCashEndF()
    {
        return number_format($this->cashEnd, 2, ',', '.');
    }

    /**
     * Set isClosed
     *
     * @param boolean $isClosed
     *
     * @return CashJournal
     */
    public function setIsClosed($isClosed)
    {
        $this->isClosed = $isClosed;

        return $this;
    }

    /**
     * Get isClosed
     *
     * @return boolean
     */
    public function getIsClosed()
    {
        return $this->isClosed;
    }

    /**
     * Set isBooked
     *
     * @param boolean $isBooked
     *
     * @return CashJournal
     */
    public function setIsBooked($isBooked)
    {
        $this->isBooked = $isBooked;

        return $this;
    }

    /**
     * Get isBooked
     *
     * @return boolean
     */
    public function getIsBooked()
    {
        return $this->isBooked;
    }

    /**
     * Add cashJournalEntry
     *
     * @param \App\Entity\CashJournalEntry $cashJournalEntry
     *
     * @return CashJournal
     */
    public function addCashJournalEntry(\App\Entity\CashJournalEntry $cashJournalEntry)
    {
        $this->cashJournalEntries[] = $cashJournalEntry;

        return $this;
    }

    /**
     * Remove cashJournalEntry
     *
     * @param \App\Entity\CashJournalEntry $cashJournalEntry
     */
    public function removeCashJournalEntry(\App\Entity\CashJournalEntry $cashJournalEntry)
    {
        $this->cashJournalEntries->removeElement($cashJournalEntry);
    }

    /**
     * Get cashJournalEntries
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getCashJournalEntries()
    {
        return $this->cashJournalEntries;
    }
}
