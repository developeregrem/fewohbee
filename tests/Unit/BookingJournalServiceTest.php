<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\AccountingAccount;
use App\Entity\AccountingSettings;
use App\Entity\AppSettings;
use App\Entity\BookingBatch;
use App\Entity\BookingEntry;
use App\Entity\Invoice;
use App\Entity\InvoiceAppartment;
use App\Entity\InvoicePosition;
use App\Entity\TaxRate;
use App\Repository\AccountingAccountRepository;
use App\Repository\BookingBatchRepository;
use App\Repository\BookingEntryRepository;
use App\Repository\TaxRateRepository;
use App\Service\BookingJournal\AccountingSettingsService;
use App\Service\AppSettingsService;
use App\Service\BookingJournal\BookingJournalService;
use App\Service\InvoiceService;
use App\Service\PriceService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class BookingJournalServiceTest extends TestCase
{
    // ── populateCashBalance ──────────────────────────────────────────

    public function testPopulateCashBalanceComputesFromRepo(): void
    {
        $batch = new BookingBatch();
        $batch->setYear(2026);
        $batch->setMonth(4);

        $entryRepo = $this->createStub(BookingEntryRepository::class);
        $entryRepo->method('getCashOpeningBalance')->willReturn(100.0);
        $entryRepo->method('getCashBatchDelta')->willReturn(30.0);

        $service = $this->createService(entryRepo: $entryRepo);
        $service->populateCashBalance($batch);

        self::assertSame(100.0, $batch->getCashStart());
        self::assertSame(130.0, $batch->getCashEnd());
    }

    public function testPopulateCashBalancesAcrossMonthsCarriesRunningTotal(): void
    {
        $jan = (new BookingBatch())->setYear(2026)->setMonth(1);
        $feb = (new BookingBatch())->setYear(2026)->setMonth(2);
        $mar = (new BookingBatch())->setYear(2026)->setMonth(3);

        $entryRepo = $this->createStub(BookingEntryRepository::class);
        $entryRepo->method('getCashOpeningForYear')->willReturn(100.0);
        $entryRepo->method('getCashDeltasByMonth')->willReturn([
            1 => 50.0,
            2 => -20.0,
            3 => 10.0,
        ]);

        $service = $this->createService(entryRepo: $entryRepo);
        $service->populateCashBalances([$mar, $jan, $feb], 2026);

        self::assertSame(100.0, $jan->getCashStart());
        self::assertSame(150.0, $jan->getCashEnd());
        self::assertSame(150.0, $feb->getCashStart());
        self::assertSame(130.0, $feb->getCashEnd());
        self::assertSame(130.0, $mar->getCashStart());
        self::assertSame(140.0, $mar->getCashEnd());
    }

    public function testPopulateCashBalancesNoOpOnEmpty(): void
    {
        $service = $this->createService();
        $service->populateCashBalances([], 2026);
        self::assertTrue(true);
    }

    public function testAssignBatchByEntryDateMovesEntryToMatchingBatch(): void
    {
        $oldBatch = (new BookingBatch())->setYear(2026)->setMonth(4);
        $targetBatch = (new BookingBatch())->setYear(2026)->setMonth(5);

        $entry = new BookingEntry();
        $entry->setBookingBatch($oldBatch);
        $entry->setDate(new \DateTime('2026-05-12'));

        $batchRepo = $this->createStub(BookingBatchRepository::class);
        $batchRepo->method('findByYearAndMonth')->willReturn($targetBatch);

        $service = $this->createService(batchRepo: $batchRepo);

        self::assertSame($targetBatch, $service->assignBatchByEntryDate($entry));
        self::assertSame($targetBatch, $entry->getBookingBatch());
    }

    public function testAssignBatchByEntryDateRejectsClosedTargetBatch(): void
    {
        $targetBatch = (new BookingBatch())->setYear(2026)->setMonth(5);
        $targetBatch->setIsClosed(true);

        $entry = new BookingEntry();
        $entry->setBookingBatch(new BookingBatch());
        $entry->setDate(new \DateTime('2026-05-12'));

        $batchRepo = $this->createStub(BookingBatchRepository::class);
        $batchRepo->method('findByYearAndMonth')->willReturn($targetBatch);

        $this->expectException(\RuntimeException::class);
        $this->createService(batchRepo: $batchRepo)->assignBatchByEntryDate($entry);
    }

    public function testRecalculateDocumentNumbersForYearsAssignsGaplessNumbers(): void
    {
        $first = (new BookingEntry())->setDocumentNumber(12);
        $second = (new BookingEntry())->setDocumentNumber(30);

        $entryRepo = $this->createStub(BookingEntryRepository::class);
        $entryRepo->method('findEntriesForDocumentNumbering')->willReturn([$first, $second]);

        $service = $this->createService(
            em: $this->createStub(EntityManagerInterface::class),
            entryRepo: $entryRepo,
        );
        $service->recalculateDocumentNumbersForYears(2026);

        self::assertSame(1, $first->getDocumentNumber());
        self::assertSame(2, $second->getDocumentNumber());
    }

    public function testCreateEntryFromStatementAssignsNextDocumentNumber(): void
    {
        $batch = (new BookingBatch())->setYear(2026)->setMonth(4);

        $batchRepo = $this->createStub(BookingBatchRepository::class);
        $batchRepo->method('findByYearAndMonth')->willReturn($batch);

        $entryRepo = $this->createStub(BookingEntryRepository::class);
        $entryRepo->method('getLastDocumentNumber')->willReturn(7);

        $entry = $this->createService(
            batchRepo: $batchRepo,
            entryRepo: $entryRepo,
        )->createEntryFromStatement(
            new \DateTimeImmutable('2026-04-01'),
            '12.34',
            null,
            null,
            null,
        );

        self::assertSame(8, $entry->getDocumentNumber());
        self::assertSame($batch, $entry->getBookingBatch());
    }

    public function testUpdateEntryDateRenumbersEvenWithinSameMonth(): void
    {
        $batch = (new BookingBatch())->setYear(2026)->setMonth(4);
        $entry = (new BookingEntry())
            ->setBookingBatch($batch)
            ->setDate(new \DateTime('2026-04-20'))
            ->setDocumentNumber(8);

        $entryRepo = $this->createStub(BookingEntryRepository::class);
        $entryRepo->method('findEntriesForDocumentNumbering')->willReturn([$entry]);

        $service = $this->createService(
            em: $this->createStub(EntityManagerInterface::class),
            entryRepo: $entryRepo,
        );

        $service->updateEntryDate($entry, new \DateTimeImmutable('2026-04-01'));

        self::assertSame('2026-04-01', $entry->getDate()->format('Y-m-d'));
        self::assertSame(1, $entry->getDocumentNumber());
    }

    public function testCreateEntriesFromInvoiceThrowsWhenBatchClosed(): void
    {
        $batch = (new BookingBatch())->setYear(2026)->setMonth(4);
        $batch->setIsClosed(true);

        $invoice = $this->createStub(Invoice::class);
        $invoice->method('getAppartments')->willReturn(new ArrayCollection());
        $invoice->method('getPositions')->willReturn(new ArrayCollection());

        $batchRepo = $this->createStub(BookingBatchRepository::class);
        $batchRepo->method('findByYearAndMonth')->willReturn($batch);

        $this->expectException(\RuntimeException::class);
        $this->createService(batchRepo: $batchRepo)->createEntriesFromInvoice($invoice);
    }

    // ── createEntriesFromInvoice — grouping & scopes ─────────────────

    public function testApartmentAndMiscSameVatSameAccountProduceTwoEntries(): void
    {
        // Apartment (Zimmer) 7%=100 and Misc (Hund) 7%=35 → 2 scope-entries even though
        // they share the same TaxRate default account (8300).
        $revenue7 = $this->makeAccount('8300', 'Erlöse 7%');

        $apt = $this->makeApartment(100.0, 7.0);        // scope=apartment
        $pos = $this->makeMiscPosition(35.0, 7.0);      // scope=misc

        $entries = $this->runWithPositions([$apt], [$pos], $revenue7, null);

        self::assertCount(2, $entries);
        // Both reference 8300
        self::assertSame($revenue7, $entries[0]->getCreditAccount());
        self::assertSame($revenue7, $entries[1]->getCreditAccount());
        // Sums correct
        $amounts = array_map(fn ($e) => (float) $e->getAmount(), $entries);
        self::assertEqualsWithDelta(35.0, min($amounts), 0.01);
        self::assertEqualsWithDelta(100.0, max($amounts), 0.01);
    }

    public function testMiscPositionsSameVatAndAccountAreMerged(): void
    {
        // Three misc positions all 19% no override → merged into 1 entry.
        $revenue19 = $this->makeAccount('8400', 'Erlöse 19%');

        $p1 = $this->makeMiscPosition(110.0, 19.0);
        $p2 = $this->makeMiscPosition(20.0, 19.0);
        $p3 = $this->makeMiscPosition(35.0, 19.0);

        $entries = $this->runWithPositions([], [$p1, $p2, $p3], $revenue19, null);

        self::assertCount(1, $entries);
        self::assertEqualsWithDelta(165.0, (float) $entries[0]->getAmount(), 0.01);
    }

    public function testDifferentVatRatesProduceSeparateMiscEntries(): void
    {
        $revenue7 = $this->makeAccount('8300', 'Erlöse 7%');
        $revenue19 = $this->makeAccount('8400', 'Erlöse 19%');

        $p7  = $this->makeMiscPosition(100.0, 7.0);
        $p19 = $this->makeMiscPosition(119.0, 19.0);

        $entries = $this->runWithPositions([], [$p7, $p19], null, null, [
            7.0  => $revenue7,
            19.0 => $revenue19,
        ]);

        self::assertCount(2, $entries);
        $amounts = array_map(fn ($e) => (float) $e->getAmount(), $entries);
        sort($amounts);
        self::assertEqualsWithDelta(100.0, $amounts[0], 0.01);
        self::assertEqualsWithDelta(119.0, $amounts[1], 0.01);
    }

    public function testExplicitRevenueAccountOverrideTakesPrecedence(): void
    {
        $defaultAccount = $this->makeAccount('8400', 'Erlöse 19%', 1);
        $overrideAccount = $this->makeAccount('8196', 'Kurtaxe', 99);

        $p1 = $this->makeMiscPosition(50.0, 19.0, $overrideAccount); // explicit override
        $p2 = $this->makeMiscPosition(69.0, 19.0);                   // default

        $entries = $this->runWithPositions([], [$p1, $p2], $defaultAccount, null);

        self::assertCount(2, $entries);
        $bySumAmount = [];
        foreach ($entries as $e) {
            $bySumAmount[(float) $e->getAmount()] = $e->getCreditAccount();
        }
        self::assertSame($overrideAccount, $bySumAmount[50.0]);
        self::assertSame($defaultAccount, $bySumAmount[69.0]);
    }

    public function testExplicitOverrideSameAsDefaultMergesIntoOneEntry(): void
    {
        // Position A has explicit 8400 set. Position B uses TaxRate default 8400.
        // Both should land in the same bucket because effective account is identical.
        $revenue19 = $this->makeAccount('8400', 'Erlöse 19%', 1);

        $p1 = $this->makeMiscPosition(50.0, 19.0, $revenue19); // explicit 8400
        $p2 = $this->makeMiscPosition(69.0, 19.0);             // default → also 8400

        $entries = $this->runWithPositions([], [$p1, $p2], $revenue19, null);

        self::assertCount(1, $entries);
        self::assertEqualsWithDelta(119.0, (float) $entries[0]->getAmount(), 0.01);
    }

    public function testZeroBruttoPositionIsSkipped(): void
    {
        $revenue = $this->makeAccount('8400', 'Erlöse 19%');
        $p = $this->makeMiscPosition(0.0, 19.0);

        $entries = $this->runWithPositions([], [$p], $revenue, null);

        self::assertCount(0, $entries);
    }

    public function testExplicitRemarkOverridesGeneratedText(): void
    {
        $revenue7 = $this->makeAccount('8300', 'Erlöse 7%');
        $p = $this->makeMiscPosition(100.0, 7.0);

        $entries = $this->runWithPositions([], [$p], $revenue7, null, [], 'Barzahlung');

        self::assertSame('Barzahlung', $entries[0]->getRemark());
    }

    public function testRemarkContainsScopeLabelAndAccountName(): void
    {
        $revenue7 = $this->makeAccount('8300', 'Erlöse 7%');
        $apt = $this->makeApartment(100.0, 7.0);

        $settings = new AccountingSettings();
        $settings->setMainPositionLabel('Übernachtung');

        $entries = $this->runWithPositions([$apt], [], $revenue7, null, [], null, $settings);

        self::assertStringContainsString('Übernachtung', $entries[0]->getRemark());
        self::assertStringContainsString('Erlöse 7%', $entries[0]->getRemark());
    }

    public function testRemarkFallsBackToAccountNameWhenNoLabel(): void
    {
        $revenue7 = $this->makeAccount('8300', 'Erlöse 7%');
        $p = $this->makeMiscPosition(100.0, 7.0);

        $entries = $this->runWithPositions([], [$p], $revenue7, null);

        self::assertSame('Erlöse 7%', $entries[0]->getRemark());
    }

    public function testDocumentNumberingStartsAfterLastInBatch(): void
    {
        $revenue = $this->makeAccount('8400', 'Erlöse 19%');
        $p = $this->makeMiscPosition(50.0, 19.0);

        $entryRepo = $this->createStub(BookingEntryRepository::class);
        $entryRepo->method('getLastDocumentNumber')->willReturn(7);

        $entries = $this->runWithPositions([], [$p], $revenue, null, [], null, null, $entryRepo);

        self::assertSame(8, $entries[0]->getDocumentNumber());
    }

    public function testSourceTypeIsWorkflow(): void
    {
        $revenue = $this->makeAccount('8400', 'Erlöse 19%');
        $p = $this->makeMiscPosition(50.0, 19.0);

        $entries = $this->runWithPositions([], [$p], $revenue, null);

        self::assertSame(BookingEntry::SOURCE_WORKFLOW, $entries[0]->getSourceType());
    }

    public function testCreateEntriesFromInvoiceCanUseExplicitDateAndSource(): void
    {
        $bank = $this->makeAccount('1200', 'Bank');
        $revenue = $this->makeAccount('8300', 'Erlöse 7%');
        $apt = $this->makeApartment(100.0, 7.0);

        $entries = $this->runWithPositions(
            [$apt],
            [],
            $revenue,
            null,
            debitAccount: $bank,
            bookingDate: new \DateTimeImmutable('2026-05-12'),
            sourceType: BookingEntry::SOURCE_MANUAL,
        );

        self::assertCount(1, $entries);
        self::assertSame($bank, $entries[0]->getDebitAccount());
        self::assertSame('2026-05-12', $entries[0]->getDate()->format('Y-m-d'));
        self::assertSame(BookingEntry::SOURCE_MANUAL, $entries[0]->getSourceType());
        self::assertSame(5, $entries[0]->getBookingBatch()->getMonth());
    }

    // ── calculateSums ↔ journal consistency ──────────────────────────

    /**
     * @return array<string, array{aptPrice: float, aptAmount: int, aptFlat: bool, aptPerRoom: bool, aptIncludesVat: bool, aptVat: float,
     *                              posPrice: float, posAmount: int, posFlat: bool, posIncludesVat: bool, posVat: float}>
     */
    public static function positionCombinationsProvider(): array
    {
        return [
            'apt:flat+brutto / misc:flat+brutto' => [
                'aptPrice' => 100.0, 'aptAmount' => 1, 'aptFlat' => true,  'aptPerRoom' => false, 'aptIncludesVat' => true,  'aptVat' => 7.0,
                'posPrice' =>  15.0, 'posAmount' => 1, 'posFlat' => true,  'posIncludesVat' => true,  'posVat' => 19.0,
            ],
            'apt:flat+netto / misc:flat+netto' => [
                'aptPrice' => 100.0, 'aptAmount' => 1, 'aptFlat' => true,  'aptPerRoom' => false, 'aptIncludesVat' => false, 'aptVat' => 7.0,
                'posPrice' =>  15.0, 'posAmount' => 1, 'posFlat' => true,  'posIncludesVat' => false, 'posVat' => 19.0,
            ],
            'apt:multinight+brutto / misc:amount+brutto' => [
                'aptPrice' =>  80.0, 'aptAmount' => 3, 'aptFlat' => false, 'aptPerRoom' => false, 'aptIncludesVat' => true,  'aptVat' => 7.0,
                'posPrice' =>  10.0, 'posAmount' => 2, 'posFlat' => false, 'posIncludesVat' => true,  'posVat' => 19.0,
            ],
            'apt:multinight+netto / misc:amount+netto' => [
                'aptPrice' =>  80.0, 'aptAmount' => 3, 'aptFlat' => false, 'aptPerRoom' => false, 'aptIncludesVat' => false, 'aptVat' => 7.0,
                'posPrice' =>  10.0, 'posAmount' => 2, 'posFlat' => false, 'posIncludesVat' => false, 'posVat' => 19.0,
            ],
            'apt:perRoom+brutto / misc:amount+brutto' => [
                'aptPrice' =>  90.0, 'aptAmount' => 2, 'aptFlat' => false, 'aptPerRoom' => true,  'aptIncludesVat' => true,  'aptVat' => 7.0,
                'posPrice' =>  12.0, 'posAmount' => 1, 'posFlat' => false, 'posIncludesVat' => true,  'posVat' => 19.0,
            ],
            'apt:perRoom+netto / misc:flat+netto' => [
                'aptPrice' =>  90.0, 'aptAmount' => 2, 'aptFlat' => false, 'aptPerRoom' => true,  'aptIncludesVat' => false, 'aptVat' => 7.0,
                'posPrice' =>  12.0, 'posAmount' => 1, 'posFlat' => true,  'posIncludesVat' => false, 'posVat' => 19.0,
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('positionCombinationsProvider')]
    public function testJournalBruttoMatchesCalculateSumsAcrossAllCombinations(
        float $aptPrice, int $aptAmount, bool $aptFlat, bool $aptPerRoom, bool $aptIncludesVat, float $aptVat,
        float $posPrice, int $posAmount, bool $posFlat, bool $posIncludesVat, float $posVat,
    ): void {
        $revenue7  = $this->makeAccount('8300', 'Erlöse 7%', 1);
        $revenue19 = $this->makeAccount('8400', 'Erlöse 19%', 2);

        $apt = new InvoiceAppartment();
        $apt->setDescription('Zimmer');
        $apt->setNumber('01');
        $apt->setPrice((string) $aptPrice);
        $apt->setVat($aptVat);
        $apt->setPersons(1);
        $apt->setBeds(1);
        $apt->setIncludesVat($aptIncludesVat);
        $apt->setIsFlatPrice($aptFlat);
        $apt->setIsPerRoom($aptPerRoom);
        $apt->setStartDate(new \DateTime('2026-04-01'));
        $apt->setEndDate(new \DateTime('2026-04-04'));

        $pos = new InvoicePosition();
        $pos->setDescription('Extra');
        $pos->setAmount($posAmount);
        $pos->setPrice((string) $posPrice);
        $pos->setVat($posVat);
        $pos->setIncludesVat($posIncludesVat);
        $pos->setIsFlatPrice($posFlat);
        $pos->setIsPerRoom(false);

        $entries = $this->runWithPositions([$apt], [$pos], null, null, [
            7.0  => $revenue7,
            19.0 => $revenue19,
        ]);
        $journalBrutto = array_sum(array_map(fn ($e) => (float) $e->getAmount(), $entries));

        $vats = []; $brutto = 0.0; $netto = 0.0; $aptTotal = 0.0; $miscTotal = 0.0;
        $this->createInvoiceService()->calculateSums(
            new ArrayCollection([$apt]),
            new ArrayCollection([$pos]),
            $vats, $brutto, $netto, $aptTotal, $miscTotal,
        );

        self::assertEqualsWithDelta($brutto, $journalBrutto, 0.01);
    }

    public function testJournalBruttoMatchesCalculateSumsForApartmentMultiNight(): void
    {
        // 3 nights × 80 € brutto, 7% VAT, isFlatPrice=false — this is the path
        // where getTotalPriceRaw() (price*amount) must equal the calculateSums formula.
        $revenue7 = $this->makeAccount('8300', 'Erlöse 7%');

        $apt = new InvoiceAppartment();
        $apt->setDescription('Zimmer');
        $apt->setNumber('01');
        $apt->setPrice('80.00');
        $apt->setVat(7.0);
        $apt->setPersons(1);
        $apt->setBeds(1);
        $apt->setIncludesVat(true);
        $apt->setIsFlatPrice(false);
        $apt->setIsPerRoom(false);
        $apt->setStartDate(new \DateTime('2026-04-01'));
        $apt->setEndDate(new \DateTime('2026-04-04'));

        $entries = $this->runWithPositions([$apt], [], $revenue7, null);

        $journalBrutto = array_sum(array_map(fn ($e) => (float) $e->getAmount(), $entries));

        $vats = []; $brutto = 0.0; $netto = 0.0; $aptTotal = 0.0; $miscTotal = 0.0;
        $this->createInvoiceService()->calculateSums(
            new ArrayCollection([$apt]),
            new ArrayCollection(),
            $vats, $brutto, $netto, $aptTotal, $miscTotal,
        );

        self::assertEqualsWithDelta($brutto, $journalBrutto, 0.01);
    }

    public function testJournalBruttoMatchesCalculateSumsForMiscPositionNettoVat(): void
    {
        // amount=2, netto price, 19% VAT — journal adds VAT on top, calculateSums does the same.
        $revenue19 = $this->makeAccount('8400', 'Erlöse 19%');

        $pos = new InvoicePosition();
        $pos->setDescription('Service');
        $pos->setAmount(2);
        $pos->setPrice('100.00');
        $pos->setVat(19.0);
        $pos->setIncludesVat(false);
        $pos->setIsFlatPrice(false);
        $pos->setIsPerRoom(false);

        $entries = $this->runWithPositions([], [$pos], $revenue19, null);

        $journalBrutto = array_sum(array_map(fn ($e) => (float) $e->getAmount(), $entries));

        $vats = []; $brutto = 0.0; $netto = 0.0; $aptTotal = 0.0; $miscTotal = 0.0;
        $this->createInvoiceService()->calculateSums(
            new ArrayCollection(),
            new ArrayCollection([$pos]),
            $vats, $brutto, $netto, $aptTotal, $miscTotal,
        );

        self::assertEqualsWithDelta($brutto, $journalBrutto, 0.01);
    }

    public function testJournalBruttoMatchesCalculateSumsMixedScenario(): void
    {
        // Apartment (3 nights × 119 € incl. 7%) + misc (2 × 10 € netto 19%).
        $revenue7  = $this->makeAccount('8300', 'Erlöse 7%', 1);
        $revenue19 = $this->makeAccount('8400', 'Erlöse 19%', 2);

        $apt = new InvoiceAppartment();
        $apt->setDescription('Zimmer');
        $apt->setNumber('01');
        $apt->setPrice('119.00');
        $apt->setVat(7.0);
        $apt->setPersons(1);
        $apt->setBeds(1);
        $apt->setIncludesVat(true);
        $apt->setIsFlatPrice(false);
        $apt->setIsPerRoom(false);
        $apt->setStartDate(new \DateTime('2026-04-01'));
        $apt->setEndDate(new \DateTime('2026-04-04'));

        $pos = new InvoicePosition();
        $pos->setDescription('Frühstück');
        $pos->setAmount(2);
        $pos->setPrice('10.00');
        $pos->setVat(19.0);
        $pos->setIncludesVat(false);
        $pos->setIsFlatPrice(false);
        $pos->setIsPerRoom(false);

        $entries = $this->runWithPositions([$apt], [$pos], null, null, [
            7.0  => $revenue7,
            19.0 => $revenue19,
        ]);

        $journalBrutto = array_sum(array_map(fn ($e) => (float) $e->getAmount(), $entries));

        $vats = []; $brutto = 0.0; $netto = 0.0; $aptTotal = 0.0; $miscTotal = 0.0;
        $this->createInvoiceService()->calculateSums(
            new ArrayCollection([$apt]),
            new ArrayCollection([$pos]),
            $vats, $brutto, $netto, $aptTotal, $miscTotal,
        );

        self::assertEqualsWithDelta($brutto, $journalBrutto, 0.01);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * @param InvoiceAppartment[] $apartments
     * @param InvoicePosition[]   $positions
     * @param array<float, AccountingAccount> $taxRateMap  vat → revenueAccount
     * @return BookingEntry[]
     */
    private function runWithPositions(
        array $apartments,
        array $positions,
        ?AccountingAccount $defaultTaxRateAccount,
        ?AccountingAccount $fallbackCredit,
        array $taxRateMap = [],
        ?string $remark = null,
        ?AccountingSettings $settings = null,
        ?BookingEntryRepository $entryRepo = null,
        ?AccountingAccount $debitAccount = null,
        ?\DateTimeInterface $bookingDate = null,
        string $sourceType = BookingEntry::SOURCE_WORKFLOW,
    ): array {
        $cash = $this->makeAccount('1000', 'Kasse');

        $targetDate = null !== $bookingDate ? \DateTime::createFromInterface($bookingDate) : new \DateTime();
        $batch = (new BookingBatch())->setYear((int) $targetDate->format('Y'))->setMonth((int) $targetDate->format('n'));

        $invoice = $this->createStub(Invoice::class);
        $invoice->method('getAppartments')->willReturn(new ArrayCollection($apartments));
        $invoice->method('getPositions')->willReturn(new ArrayCollection($positions));
        $invoice->method('getNumber')->willReturn('R-2026-001');
        $invoice->method('getId')->willReturn(1);

        $batchRepo = $this->createStub(BookingBatchRepository::class);
        $batchRepo->method('findByYearAndMonth')->willReturn($batch);

        $entryRepo ??= $this->createStub(BookingEntryRepository::class);
        $entryRepo->method('getLastDocumentNumber')->willReturn(0);

        $accountRepo = $this->createStub(AccountingAccountRepository::class);
        $accountRepo->method('findCashAccount')->willReturn($cash);

        $taxRateRepo = $this->createStub(TaxRateRepository::class);
        $taxRateRepo->method('findByRate')->willReturnCallback(
            function (float $rate) use ($taxRateMap, $defaultTaxRateAccount) {
                foreach ($taxRateMap as $mapRate => $account) {
                    if (abs($rate - $mapRate) < 0.001) {
                        $tr = new TaxRate();
                        $tr->setRate((string) $mapRate);
                        $tr->setRevenueAccount($account);

                        return $tr;
                    }
                }
                if (null !== $defaultTaxRateAccount) {
                    $tr = new TaxRate();
                    $tr->setRate((string) $rate);
                    $tr->setRevenueAccount($defaultTaxRateAccount);

                    return $tr;
                }

                return null;
            }
        );

        $settingsService = $this->createStub(AccountingSettingsService::class);
        $settingsService->method('getActivePreset')->willReturn(null);
        $settingsService->method('getSettings')->willReturn($settings ?? new AccountingSettings());

        $debit = $debitAccount ?? ($fallbackCredit ? null : $cash);

        return $this->createService(
            batchRepo: $batchRepo,
            entryRepo: $entryRepo,
            accountRepo: $accountRepo,
            taxRateRepo: $taxRateRepo,
            settingsService: $settingsService,
        )->createEntriesFromInvoice($invoice, $debit, $fallbackCredit, $remark, $bookingDate, $sourceType);
    }

    private function makeApartment(float $price, float $vat): InvoiceAppartment
    {
        $apt = new InvoiceAppartment();
        $apt->setDescription('Zimmer');
        $apt->setNumber('01');
        $apt->setPrice((string) $price);
        $apt->setVat($vat);
        $apt->setPersons(1);
        $apt->setBeds(2);
        $apt->setIncludesVat(true);
        $apt->setIsFlatPrice(true);
        $apt->setIsPerRoom(true);
        $apt->setStartDate(new \DateTime('2026-04-01'));
        $apt->setEndDate(new \DateTime('2026-04-02'));

        return $apt;
    }

    private function makeMiscPosition(float $price, float $vat, ?AccountingAccount $revenueAccount = null): InvoicePosition
    {
        $pos = new InvoicePosition();
        $pos->setDescription('Position');
        $pos->setAmount(1);
        $pos->setPrice((string) $price);
        $pos->setVat($vat);
        $pos->setIncludesVat(true);
        $pos->setIsFlatPrice(true);
        $pos->setIsPerRoom(false);
        $pos->setRevenueAccount($revenueAccount);

        return $pos;
    }

    private function makeAccount(string $number, string $name, ?int $id = null): AccountingAccount
    {
        $account = new AccountingAccount();
        $account->setAccountNumber($number);
        $account->setName($name);
        if (null !== $id) {
            $ref = new \ReflectionProperty(AccountingAccount::class, 'id');
            $ref->setValue($account, $id);
        }

        return $account;
    }

    private function createInvoiceService(): InvoiceService
    {
        $appSettings = new AppSettings();
        $appSettings->setInvoiceFilenamePattern('Invoice-<number>');
        $appSettingsService = $this->createStub(AppSettingsService::class);
        $appSettingsService->method('getSettings')->willReturn($appSettings);

        return new InvoiceService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(PriceService::class),
            $this->createStub(TranslatorInterface::class),
            $appSettingsService,
        );
    }

    private function createService(
        ?EntityManagerInterface $em = null,
        ?BookingBatchRepository $batchRepo = null,
        ?BookingEntryRepository $entryRepo = null,
        ?AccountingAccountRepository $accountRepo = null,
        ?TaxRateRepository $taxRateRepo = null,
        ?AccountingSettingsService $settingsService = null,
    ): BookingJournalService {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        if (null === $settingsService) {
            $settingsService = $this->createStub(AccountingSettingsService::class);
            $settingsService->method('getActivePreset')->willReturn(null);
            $settingsService->method('getSettings')->willReturn(new AccountingSettings());
        }

        return new BookingJournalService(
            $em ?? $this->createStub(EntityManagerInterface::class),
            $batchRepo ?? $this->createStub(BookingBatchRepository::class),
            $entryRepo ?? $this->createStub(BookingEntryRepository::class),
            $accountRepo ?? $this->createStub(AccountingAccountRepository::class),
            $taxRateRepo ?? $this->createStub(TaxRateRepository::class),
            $translator,
            $settingsService,
        );
    }
}
