<?php

declare(strict_types=1);

namespace App\Tests\Unit\BookingJournal\BankImport;

use App\Dto\BookingJournal\BankImport\ImportState;
use App\Entity\AccountingAccount;
use App\Entity\BookingEntry;
use App\Entity\Invoice;
use App\Entity\TaxRate;
use App\Repository\AccountingAccountRepository;
use App\Repository\BookingEntryRepository;
use App\Repository\InvoiceRepository;
use App\Repository\TaxRateRepository;
use App\Service\BookingJournal\BankImport\BankImportDraftSession;
use App\Service\BookingJournal\BankImport\BankStatementCommitter;
use App\Service\BookingJournal\BookingJournalService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

#[AllowMockObjectsWithoutExpectations]
final class BankStatementCommitterTest extends TestCase
{
    public function testMatchedInvoiceWithoutExistingEntriesCreatesVatSplitEntriesFromInvoice(): void
    {
        $bankAccount = $this->makeAccount(1200, '1200', 'Bank');
        $invoice = $this->makeInvoice(42, 'RE-42');
        $created = [new BookingEntry(), new BookingEntry()];
        $state = $this->createState([
            'amount' => '585.00',
            'valueDate' => '2026-04-25',
            'matchedInvoiceId' => 42,
            'matchedInvoiceNumber' => 'RE-42',
            'matchedInvoiceAmountMatches' => true,
            'status' => ImportState::LINE_STATUS_READY,
            'userDebitAccountId' => 1200,
        ]);

        $journal = $this->createMock(BookingJournalService::class);
        $journal->expects(self::once())
            ->method('createEntriesFromInvoice')
            ->with(
                $invoice,
                $bankAccount,
                null,
                null,
                self::callback(static fn (\DateTimeInterface $date): bool => '2026-04-25' === $date->format('Y-m-d')),
                BookingEntry::SOURCE_MANUAL,
            )
            ->willReturn($created);
        $journal->expects(self::never())->method('createEntryFromStatement');

        $committer = $this->createCommitter(
            journal: $journal,
            entryRepo: $this->entryRepo([]),
            invoiceRepo: $this->invoiceRepo($invoice),
            accountRepo: $this->accountRepo([$bankAccount]),
        );

        $result = $committer->commit($state, $bankAccount);

        self::assertSame(1, $result['committed']);
        self::assertSame(0, $result['redated']);
    }

    public function testMatchedInvoiceWithExistingEntriesUpdatesDateAndBankDebitAccount(): void
    {
        $oldAccount = $this->makeAccount(1000, '1000', 'Kasse');
        $bankAccount = $this->makeAccount(1200, '1200', 'Bank');
        $existing = (new BookingEntry())
            ->setAmount('585.00')
            ->setDebitAccount($oldAccount)
            ->setInvoiceId(42);
        $state = $this->createState([
            'amount' => '585.00',
            'valueDate' => '2026-04-25',
            'matchedInvoiceId' => 42,
            'matchedInvoiceNumber' => 'RE-42',
            'matchedInvoiceAmountMatches' => true,
            'status' => ImportState::LINE_STATUS_READY,
            'userDebitAccountId' => 1200,
        ]);

        $journal = $this->createMock(BookingJournalService::class);
        $journal->expects(self::once())
            ->method('updateEntryDate')
            ->with($existing, self::callback(static fn (\DateTimeInterface $date): bool => '2026-04-25' === $date->format('Y-m-d')));
        $journal->expects(self::never())->method('createEntriesFromInvoice');
        $journal->expects(self::never())->method('createEntryFromStatement');

        $committer = $this->createCommitter(
            journal: $journal,
            entryRepo: $this->entryRepo([$existing]),
            invoiceRepo: $this->invoiceRepo(null),
            accountRepo: $this->accountRepo([$bankAccount]),
        );

        $result = $committer->commit($state, $bankAccount);

        self::assertSame($bankAccount, $existing->getDebitAccount());
        self::assertSame(1, $result['committed']);
        self::assertSame(1, $result['redated']);
    }

    public function testMatchedInvoiceAmountMismatchDoesNotRewriteExistingInvoiceEntries(): void
    {
        $oldAccount = $this->makeAccount(1000, '1000', 'Kasse');
        $bankAccount = $this->makeAccount(1200, '1200', 'Bank');
        $fallbackCredit = $this->makeAccount(8400, '8400', 'Erlöse');
        $taxRate = $this->makeTaxRate(19, '19%', '19.00');
        $existing = (new BookingEntry())
            ->setAmount('585.00')
            ->setDebitAccount($oldAccount)
            ->setInvoiceId(42);
        $manualEntry = new BookingEntry();
        $state = $this->createState([
            'amount' => '200.00',
            'valueDate' => '2026-04-25',
            'matchedInvoiceId' => 42,
            'matchedInvoiceNumber' => 'RE-42',
            'matchedInvoiceAmountMatches' => false,
            'status' => ImportState::LINE_STATUS_READY,
            'userDebitAccountId' => 1200,
            'userCreditAccountId' => 8400,
            'userTaxRateId' => 19,
        ]);

        $journal = $this->createMock(BookingJournalService::class);
        $journal->expects(self::never())->method('updateEntryDate');
        $journal->expects(self::once())->method('recalculateDocumentNumbersForYears')->with(2026);
        $journal->expects(self::once())
            ->method('createEntryFromStatement')
            ->with(
                self::callback(static fn (\DateTimeInterface $date): bool => '2026-04-25' === $date->format('Y-m-d')),
                '200.00',
                $bankAccount,
                $fallbackCredit,
                null,
                'RE-42',
                42,
                null,
                $taxRate,
            )
            ->willReturn($manualEntry);

        $committer = $this->createCommitter(
            journal: $journal,
            entryRepo: $this->entryRepo([$existing]),
            invoiceRepo: $this->invoiceRepo(null),
            accountRepo: $this->accountRepo([$bankAccount, $fallbackCredit]),
            taxRateRepo: $this->taxRateRepo([$taxRate]),
        );

        $result = $committer->commit($state, $bankAccount);

        self::assertSame($oldAccount, $existing->getDebitAccount());
        self::assertSame(1, $result['committed']);
        self::assertSame(0, $result['redated']);
    }

    public function testOutgoingStatementLineCreatesPositiveJournalAmount(): void
    {
        $bankAccount = $this->makeAccount(1200, '1200', 'Bank');
        $expense = $this->makeAccount(6000, '6000', 'Aufwand');
        $manualEntry = new BookingEntry();
        $state = $this->createState([
            'amount' => '-500.00',
            'valueDate' => '2026-04-25',
            'status' => ImportState::LINE_STATUS_READY,
            'userDebitAccountId' => 6000,
            'userCreditAccountId' => 1200,
        ]);

        $journal = $this->createMock(BookingJournalService::class);
        $journal->expects(self::once())->method('recalculateDocumentNumbersForYears')->with(2026);
        $journal->expects(self::once())
            ->method('createEntryFromStatement')
            ->with(
                self::callback(static fn (\DateTimeInterface $date): bool => '2026-04-25' === $date->format('Y-m-d')),
                '500.00',
                $expense,
                $bankAccount,
                null,
                null,
                null,
                null,
                null,
            )
            ->willReturn($manualEntry);

        $committer = $this->createCommitter(
            journal: $journal,
            entryRepo: $this->entryRepo([]),
            invoiceRepo: $this->invoiceRepo(null),
            accountRepo: $this->accountRepo([$bankAccount, $expense]),
        );

        $result = $committer->commit($state, $bankAccount);

        self::assertSame(1, $result['committed']);
    }

    public function testOutgoingSplitStatementLineCreatesPositiveJournalAmounts(): void
    {
        $bankAccount = $this->makeAccount(1200, '1200', 'Bank');
        $expense = $this->makeAccount(6000, '6000', 'Aufwand');
        $state = $this->createState([
            'amount' => '-100.00',
            'valueDate' => '2026-04-25',
            'status' => ImportState::LINE_STATUS_READY,
            'splits' => [
                ['amount' => '-30.00', 'debitAccountId' => 6000, 'creditAccountId' => 1200],
                ['amount' => '-70.00', 'debitAccountId' => 6000, 'creditAccountId' => 1200],
            ],
        ]);

        $seenAmounts = [];
        $journal = $this->createMock(BookingJournalService::class);
        $journal->expects(self::once())->method('recalculateDocumentNumbersForYears')->with(2026);
        $journal->expects(self::exactly(2))
            ->method('createEntryFromStatement')
            ->willReturnCallback(function (
                \DateTimeInterface $date,
                string $amount,
                ?AccountingAccount $debitAccount,
                ?AccountingAccount $creditAccount,
            ) use (&$seenAmounts, $expense, $bankAccount): BookingEntry {
                self::assertSame('2026-04-25', $date->format('Y-m-d'));
                self::assertSame($expense, $debitAccount);
                self::assertSame($bankAccount, $creditAccount);
                $seenAmounts[] = $amount;

                return new BookingEntry();
            });

        $committer = $this->createCommitter(
            journal: $journal,
            entryRepo: $this->entryRepo([]),
            invoiceRepo: $this->invoiceRepo(null),
            accountRepo: $this->accountRepo([$bankAccount, $expense]),
        );

        $result = $committer->commit($state, $bankAccount);

        self::assertSame(1, $result['committed']);
        self::assertSame(['30.00', '70.00'], $seenAmounts);
    }


    /**
     * @param list<BookingEntry> $existing
     */
    private function entryRepo(array $existing): BookingEntryRepository
    {
        $repo = $this->createMock(BookingEntryRepository::class);
        $repo->method('findBy')->willReturn($existing);

        return $repo;
    }

    private function invoiceRepo(?Invoice $invoice): InvoiceRepository
    {
        $repo = $this->createMock(InvoiceRepository::class);
        $repo->method('find')->willReturn($invoice);

        return $repo;
    }

    /**
     * @param list<AccountingAccount> $accounts
     */
    private function accountRepo(array $accounts): AccountingAccountRepository
    {
        $repo = $this->createMock(AccountingAccountRepository::class);
        $repo->method('findAll')->willReturn($accounts);

        return $repo;
    }

    private function createCommitter(
        BookingJournalService $journal,
        BookingEntryRepository $entryRepo,
        InvoiceRepository $invoiceRepo,
        AccountingAccountRepository $accountRepo,
        ?TaxRateRepository $taxRateRepo = null,
    ): BankStatementCommitter {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('beginTransaction');
        $em->expects(self::once())->method('flush');
        $em->expects(self::once())->method('commit');

        return new BankStatementCommitter(
            $em,
            $journal,
            $entryRepo,
            $accountRepo,
            $invoiceRepo,
            $taxRateRepo ?? $this->taxRateRepo([]),
            new BankImportDraftSession($this->requestStack()),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createState(array $overrides): ImportState
    {
        $state = new ImportState(
            sessionImportId: '00000000-0000-0000-0000-000000000001',
            bankAccountId: 1200,
            fileFormat: 'csv_generic',
            bankCsvProfileId: 1,
            originalFilename: 'test.csv',
            sourceIban: null,
            periodFrom: null,
            periodTo: null,
            createdAt: new \DateTimeImmutable(),
        );
        $state->lines[] = array_merge([
            'idx' => 0,
            'bookDate' => '2026-04-24',
            'valueDate' => '2026-04-24',
            'amount' => '0.00',
            'counterpartyName' => '',
            'counterpartyIban' => null,
            'purpose' => '',
            'endToEndId' => null,
            'mandateReference' => null,
            'creditorId' => null,
            'fingerprint' => str_repeat('a', 64),
            'status' => ImportState::LINE_STATUS_PENDING,
            'isIgnored' => false,
            'isDuplicate' => false,
            'userDebitAccountId' => null,
            'userCreditAccountId' => null,
            'userTaxRateId' => null,
            'userRemark' => null,
            'appliedRuleId' => null,
            'matchedInvoiceId' => null,
            'matchedInvoiceNumber' => null,
            'matchedInvoiceAmountMatches' => false,
            'splits' => [],
        ], $overrides);

        return $state;
    }

    private function makeAccount(int $id, string $number, string $name): AccountingAccount
    {
        $account = new AccountingAccount();
        (new \ReflectionProperty(AccountingAccount::class, 'id'))->setValue($account, $id);
        $account->setAccountNumber($number);
        $account->setName($name);

        return $account;
    }

    private function makeInvoice(int $id, string $number): Invoice
    {
        $invoice = new Invoice();
        (new \ReflectionProperty(Invoice::class, 'id'))->setValue($invoice, $id);
        $invoice->setNumber($number);

        return $invoice;
    }

    private function makeTaxRate(int $id, string $name, string $rate): TaxRate
    {
        $taxRate = new TaxRate();
        (new \ReflectionProperty(TaxRate::class, 'id'))->setValue($taxRate, $id);
        $taxRate->setName($name);
        $taxRate->setRate($rate);

        return $taxRate;
    }

    /**
     * @param list<TaxRate> $taxRates
     */
    private function taxRateRepo(array $taxRates): TaxRateRepository
    {
        $repo = $this->createMock(TaxRateRepository::class);
        $repo->method('findAll')->willReturn($taxRates);

        return $repo;
    }

    private function requestStack(): RequestStack
    {
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);

        $stack = new RequestStack();
        $stack->push($request);

        return $stack;
    }
}
