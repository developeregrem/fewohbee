<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\AccountingAccount;
use App\Entity\BookingEntry;
use PHPUnit\Framework\TestCase;

final class BookingEntryTest extends TestCase
{
    // ── getIncomes / getExpenses ─────────────────────────────────────

    public function testGetIncomesReturnsAmountWhenDebitIsCash(): void
    {
        $entry = $this->createEntryWithAccounts(debitCash: true, creditCash: false, amount: '150.00');

        self::assertSame(150.0, $entry->getIncomes());
        self::assertSame(0.0, $entry->getExpenses());
    }

    public function testGetExpensesReturnsAmountWhenCreditIsCash(): void
    {
        $entry = $this->createEntryWithAccounts(debitCash: false, creditCash: true, amount: '75.50');

        self::assertSame(0.0, $entry->getIncomes());
        self::assertSame(75.5, $entry->getExpenses());
    }

    public function testIncomesAndExpensesAreZeroWhenNoCashAccount(): void
    {
        $entry = $this->createEntryWithAccounts(debitCash: false, creditCash: false, amount: '200.00');

        self::assertSame(0.0, $entry->getIncomes());
        self::assertSame(0.0, $entry->getExpenses());
    }

    public function testIncomesAndExpensesAreZeroWhenAccountsAreNull(): void
    {
        $entry = new BookingEntry();
        $entry->setAmount('100.00');

        self::assertSame(0.0, $entry->getIncomes());
        self::assertSame(0.0, $entry->getExpenses());
    }

    // ── getCounterAccount ───────────────────────────────────────────

    public function testCounterAccountReturnsCreditLabelWhenDebitIsCash(): void
    {
        $cash = $this->makeAccount('1000', 'Kasse', true);
        $revenue = $this->makeAccount('8300', 'Erlöse 7%', false);

        $entry = new BookingEntry();
        $entry->setDebitAccount($cash);
        $entry->setCreditAccount($revenue);

        self::assertSame('8300 - Erlöse 7%', $entry->getCounterAccount());
    }

    public function testCounterAccountReturnsDebitLabelWhenCreditIsCash(): void
    {
        $cash = $this->makeAccount('1000', 'Kasse', true);
        $office = $this->makeAccount('4930', 'Bürobedarf', false);

        $entry = new BookingEntry();
        $entry->setDebitAccount($office);
        $entry->setCreditAccount($cash);

        self::assertSame('4930 - Bürobedarf', $entry->getCounterAccount());
    }

    public function testCounterAccountFallsBackToLegacyValue(): void
    {
        $entry = new BookingEntry();
        $entry->setCounterAccountLegacy('Alte Bezeichnung');

        self::assertSame('Alte Bezeichnung', $entry->getCounterAccount());
    }

    public function testCounterAccountReturnsNullWhenNothingSet(): void
    {
        $entry = new BookingEntry();

        self::assertNull($entry->getCounterAccount());
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function createEntryWithAccounts(bool $debitCash, bool $creditCash, string $amount): BookingEntry
    {
        $entry = new BookingEntry();
        $entry->setAmount($amount);
        $entry->setDebitAccount($this->makeAccount('1000', 'Debit', $debitCash));
        $entry->setCreditAccount($this->makeAccount('8300', 'Credit', $creditCash));

        return $entry;
    }

    private function makeAccount(string $number, string $name, bool $isCash): AccountingAccount
    {
        $account = new AccountingAccount();
        $account->setAccountNumber($number);
        $account->setName($name);
        $account->setIsCashAccount($isCash);

        return $account;
    }
}
