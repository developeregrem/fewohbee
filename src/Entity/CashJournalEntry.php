<?php

declare(strict_types=1);

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
     * Get id.
     *
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set incomes.
     *
     * @param string $incomes
     *
     * @return CashJournalEntry
     */
    public function setIncomes($incomes): static
    {
        $this->incomes = $incomes;

        return $this;
    }

    /**
     * Get incomes.
     *
     * @return string
     */
    public function getIncomes()
    {
        return $this->incomes;
    }

    /**
     * Set expenses.
     *
     * @param string $expenses
     *
     * @return CashJournalEntry
     */
    public function setExpenses($expenses): static
    {
        $this->expenses = $expenses;

        return $this;
    }

    /**
     * Get expenses.
     *
     * @return string
     */
    public function getExpenses()
    {
        return $this->expenses;
    }

    /**
     * Set counterAccount.
     *
     * @param string $counterAccount
     *
     * @return CashJournalEntry
     */
    public function setCounterAccount(string $counterAccount): static
    {
        $this->counterAccount = $counterAccount;

        return $this;
    }

    /**
     * Get counterAccount.
     *
     * @return string|null
     */
    public function getCounterAccount(): ?string
    {
        return $this->counterAccount;
    }

    /**
     * Set invoiceNumber.
     *
     * @param string $invoiceNumber
     *
     * @return CashJournalEntry
     */
    public function setInvoiceNumber(string $invoiceNumber): static
    {
        $this->invoiceNumber = $invoiceNumber;

        return $this;
    }

    /**
     * Get invoiceNumber.
     *
     * @return string|null
     */
    public function getInvoiceNumber(): ?string
    {
        return $this->invoiceNumber;
    }

    /**
     * Set documentNumber.
     *
     * @param int $documentNumber
     *
     * @return CashJournalEntry
     */
    public function setDocumentNumber(int $documentNumber): static
    {
        $this->documentNumber = $documentNumber;

        return $this;
    }

    /**
     * Get documentNumber.
     *
     * @return int
     */
    public function getDocumentNumber(): int
    {
        return $this->documentNumber;
    }

    /**
     * Set date.
     *
     * @param \DateTime $date
     *
     * @return CashJournalEntry
     */
    public function setDate(\DateTime $date): static
    {
        $this->date = $date;

        return $this;
    }

    /**
     * Get date.
     *
     * @return \DateTime
     */
    public function getDate(): \DateTime
    {
        return $this->date;
    }

    /**
     * Set remark.
     *
     * @param string $remark
     *
     * @return CashJournalEntry
     */
    public function setRemark(string $remark): static
    {
        $this->remark = $remark;

        return $this;
    }

    /**
     * Get remark.
     *
     * @return string|null
     */
    public function getRemark(): ?string
    {
        return $this->remark;
    }

    /**
     * Set cashJournal.
     *
     * @param CashJournal|null $cashJournal
     *
     * @return CashJournalEntry
     */
    public function setCashJournal(CashJournal $cashJournal = null): static
    {
        $this->cashJournal = $cashJournal;

        return $this;
    }

    /**
     * Get cashJournal.
     *
     * @return CashJournal
     */
    public function getCashJournal(): CashJournal
    {
        return $this->cashJournal;
    }

    /**
     * Set inventory.
     *
     * @param string $inventory
     *
     * @return CashJournalEntry
     */
    public function setInventory($inventory): static
    {
        $this->inventory = $inventory;

        return $this;
    }

    /**
     * Get incomes formatted.
     *
     * @return string
     */
    public function getIncomesF(): string
    {
        return number_format((float) $this->incomes, 2, ',', '.');
    }

    /**
     * Get expenses formatted.
     *
     * @return string
     */
    public function getExpensesF(): string
    {
        return number_format((float) $this->expenses, 2, ',', '.');
    }

    /**
     * Get inventory formatted.
     *
     * @return string
     */
    public function getInventory()
    {
        return $this->inventory;
    }

    public function getInventoryF(): string
    {
        return number_format((float) $this->inventory, 2, ',', '.');
    }

    /**
     * Get documentNumber formatted.
     */
    public function getDocumentNumberF(): string
    {
        return sprintf('%04d', $this->documentNumber);
    }
}
