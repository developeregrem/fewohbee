<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Wraps a BookingEntry to expose the old CashJournalEntry interface
 * (incomes, expenses, inventory, counterAccount) for PDF template compatibility.
 */
class CashJournalEntryAdapter
{
    public function __construct(
        private readonly BookingEntry $entry,
        private readonly float $incomes,
        private readonly float $expenses,
        private readonly float $inventory,
        private readonly string $counterAccount,
    ) {
    }

    public function getEntry(): BookingEntry
    {
        return $this->entry;
    }

    public function getIncomes(): float
    {
        return $this->incomes;
    }

    public function getIncomesF(): string
    {
        return number_format($this->incomes, 2, ',', '.');
    }

    public function getExpenses(): float
    {
        return $this->expenses;
    }

    public function getExpensesF(): string
    {
        return number_format($this->expenses, 2, ',', '.');
    }

    public function getInventory(): float
    {
        return $this->inventory;
    }

    public function getInventoryF(): string
    {
        return number_format($this->inventory, 2, ',', '.');
    }

    public function getCounterAccount(): string
    {
        return $this->counterAccount;
    }

    public function getInvoiceNumber(): ?string
    {
        return $this->entry->getInvoiceNumber();
    }

    public function getDocumentNumber(): int
    {
        return $this->entry->getDocumentNumber();
    }

    public function getDocumentNumberF(): string
    {
        return sprintf('%04d', $this->entry->getDocumentNumber());
    }

    public function getDate(): \DateTimeInterface
    {
        return $this->entry->getDate();
    }

    public function getRemark(): ?string
    {
        return $this->entry->getRemark();
    }
}
