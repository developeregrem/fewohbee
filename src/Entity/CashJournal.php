<?php

declare(strict_types=1);

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
     * Get id.
     *
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set cashYear.
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
     * Get cashYear.
     *
     * @return string
     */
    public function getCashYear()
    {
        return $this->cashYear;
    }

    /**
     * Set cashMonth.
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
     * Get cashMonth.
     *
     * @return string
     */
    public function getCashMonth()
    {
        return $this->cashMonth;
    }

    /**
     * Set cashStart.
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
     * Get cashStart.
     */
    public function getCashStart(): float
    {
        return (float) $this->cashStart;
    }

    /**
     * Get cashStart.
     */
    public function getCashStartF(): string
    {
        return number_format((float) $this->cashStart, 2, ',', '.');
    }

    /**
     * Set cashEnd.
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
     * Get cashEnd.
     *
     * @return string
     */
    public function getCashEnd()
    {
        return $this->cashEnd;
    }

    /**
     * Get cashStart.
     */
    public function getCashEndF(): string
    {
        return number_format((float) $this->cashEnd, 2, ',', '.');
    }

    /**
     * Set isClosed.
     *
     * @return CashJournal
     */
    public function setIsClosed(bool $isClosed): static
    {
        $this->isClosed = $isClosed;

        return $this;
    }

    /**
     * Get isClosed.
     */
    public function getIsClosed(): bool
    {
        return $this->isClosed;
    }

    /**
     * Set isBooked.
     *
     * @return CashJournal
     */
    public function setIsBooked(bool $isBooked): static
    {
        $this->isBooked = $isBooked;

        return $this;
    }

    /**
     * Get isBooked.
     */
    public function getIsBooked(): bool
    {
        return $this->isBooked;
    }

    /**
     * Add cashJournalEntry.
     *
     * @param \App\Entity\CashJournalEntry $cashJournalEntry
     *
     * @return CashJournal
     */
    public function addCashJournalEntry(CashJournalEntry $cashJournalEntry)
    {
        $this->cashJournalEntries[] = $cashJournalEntry;

        return $this;
    }

    /**
     * Remove cashJournalEntry.
     *
     * @param \App\Entity\CashJournalEntry $cashJournalEntry
     */
    public function removeCashJournalEntry(CashJournalEntry $cashJournalEntry): void
    {
        $this->cashJournalEntries->removeElement($cashJournalEntry);
    }

    /**
     * Get cashJournalEntries.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getCashJournalEntries()
    {
        return $this->cashJournalEntries;
    }
}
