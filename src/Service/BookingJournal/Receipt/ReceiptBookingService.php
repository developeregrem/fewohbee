<?php

declare(strict_types=1);

namespace App\Service\BookingJournal\Receipt;

use App\Entity\AccountingAccount;
use App\Entity\BookingBatch;
use App\Entity\BookingEntry;
use App\Entity\TaxRate;
use App\Repository\AccountingAccountRepository;
use App\Repository\BookingBatchRepository;
use App\Repository\BookingEntryRepository;
use App\Repository\TaxRateRepository;
use App\Service\BookingJournal\AccountingSettingsService;
use App\Service\BookingJournal\BookingJournalService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Uid\Uuid;

/**
 * Books a receipt that has to be spread over several accounts and VAT rates.
 *
 * Such receipts are what the bank statement rules cannot handle on their own: a supermarket bill
 * with food at 7 % and cleaning agents at 19 %, or a hardware store bill for tools and repairs.
 * The payment is booked either already (bank import, a cash entry typed in one piece) or not at
 * all (cash). plan() validates without writing anything; book() carries the plan out:
 *
 * - split: the existing entry becomes the first part, so its date, payment account, source and
 *   import fingerprint stay; the other parts are added with the same date and payment account.
 * - new: every part is created against the given cash or bank account.
 *
 * Parts of one receipt share a splitGroupUuid, which the journal shows as one group, and the
 * year's document numbers are recalculated afterwards like after any other change.
 *
 * Refusals are ReceiptBookingException with a translatable message for the person booking.
 */
class ReceiptBookingService
{
    public const MAX_LINES = 20;
    private const DUPLICATE_WINDOW_DAYS = 3;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BookingEntryRepository $entryRepository,
        private readonly BookingBatchRepository $batchRepository,
        private readonly AccountingAccountRepository $accountRepository,
        private readonly TaxRateRepository $taxRateRepository,
        private readonly AccountingSettingsService $settingsService,
        private readonly BookingJournalService $journalService,
    ) {
    }

    /**
     * @throws ReceiptBookingException when the request cannot be booked as it stands
     */
    public function plan(ReceiptBookingRequest $request): ReceiptBookingPlan
    {
        if ([] === $request->lines || \count($request->lines) > self::MAX_LINES) {
            throw new ReceiptBookingException('accounting.receipt_booking.error.lines_count', ['%max%' => self::MAX_LINES]);
        }

        $warnings = [];
        if (null !== $request->entryId) {
            [$entry, $date, $paymentAccount, $paymentOnCredit] = $this->resolveExistingEntry($request);
        } else {
            $entry = null;
            [$date, $paymentAccount, $paymentOnCredit] = $this->resolveNewBooking($request);
        }

        $preset = $this->settingsService->getActivePreset();
        $parts = [];
        $totalCents = 0;
        foreach ($request->lines as $index => $line) {
            $cents = self::toCents($line->amount, $index + 1);
            $totalCents += $cents;
            $parts[] = [
                'account' => $this->resolveLineAccount($line->accountNumber, $preset, $index),
                'amount' => self::fromCents($cents),
                'taxRate' => $this->resolveTaxRate($line->taxRatePercent, $date, $preset, $index),
            ];
        }
        $total = self::fromCents($totalCents);

        if (null !== $entry && $total !== $entry->getAmount()) {
            throw new ReceiptBookingException('accounting.receipt_booking.error.sum_mismatch', ['%sum%' => $total, '%booked%' => $entry->getAmount()]);
        }

        if (null === $entry) {
            $warnings = [...$warnings, ...$this->possibleDuplicates($date, $total, $paymentAccount)];
        }
        if (null !== $request->invoiceNumber) {
            foreach ($this->entryRepository->findBy(['invoiceNumber' => $request->invoiceNumber], ['date' => 'ASC'], 5) as $other) {
                if ($other !== $entry) {
                    $warnings[] = new TranslatableMessage('accounting.receipt_booking.warning.invoice_number_used', ['%number%' => $request->invoiceNumber, '%date%' => $other->getDate()->format('d.m.Y')]);
                }
            }
        }

        return new ReceiptBookingPlan(
            entry: $entry,
            date: $date,
            paymentAccount: $paymentAccount,
            paymentOnCredit: $paymentOnCredit,
            parts: $parts,
            total: $total,
            invoiceNumber: $request->invoiceNumber ?? $entry?->getInvoiceNumber(),
            remark: $request->remark ?? $entry?->getRemark(),
            warnings: $warnings,
        );
    }

    /**
     * Writes the plan in one transaction and returns the resulting entries, the first part first.
     *
     * @return list<BookingEntry>
     */
    public function book(ReceiptBookingPlan $plan): array
    {
        return $this->em->wrapInTransaction(function () use ($plan): array {
            $groupUuid = \count($plan->parts) > 1 ? Uuid::v4()->toRfc4122() : null;
            $entries = [];

            foreach ($plan->parts as $index => $part) {
                $debit = $plan->paymentOnCredit ? $part['account'] : $plan->paymentAccount;
                $credit = $plan->paymentOnCredit ? $plan->paymentAccount : $part['account'];

                if (0 === $index && null !== $plan->entry) {
                    $entry = $plan->entry;
                    $entry->setAmount($part['amount']);
                    $entry->setDebitAccount($debit);
                    $entry->setCreditAccount($credit);
                    $entry->setTaxRate($part['taxRate']);
                    $entry->setInvoiceNumber($plan->invoiceNumber);
                    $entry->setRemark($plan->remark);
                    $entry->setSplitGroupUuid($groupUuid);
                } else {
                    $entry = $this->journalService->createEntryFromStatement(
                        $plan->date,
                        $part['amount'],
                        $debit,
                        $credit,
                        $plan->remark,
                        $plan->invoiceNumber,
                        null,
                        $groupUuid,
                        $part['taxRate'],
                    );
                }
                // The receipt is the document an entry booked ahead of it was waiting for.
                if (null !== $plan->invoiceNumber) {
                    $entry->setRequiresDocumentNumber(false);
                }
                $entries[] = $entry;
            }

            $this->em->flush();
            $this->journalService->recalculateDocumentNumbersForYears((int) $plan->date->format('Y'));

            return $entries;
        });
    }

    /**
     * @return array{0: BookingEntry, 1: \DateTimeImmutable, 2: AccountingAccount, 3: bool}
     */
    private function resolveExistingEntry(ReceiptBookingRequest $request): array
    {
        $entry = $this->entryRepository->find((int) $request->entryId);
        if (!$entry instanceof BookingEntry) {
            throw new ReceiptBookingException('accounting.receipt_booking.error.unknown_entry');
        }
        $this->assertBatchOpen($entry->getBookingBatch());
        if ($entry->isOpeningBalance()) {
            throw new ReceiptBookingException('accounting.receipt_booking.error.entry_opening_balance');
        }
        if (null !== $entry->getInvoiceId()) {
            throw new ReceiptBookingException('accounting.receipt_booking.error.entry_from_invoice');
        }
        if (null !== $entry->getSplitGroupUuid()) {
            throw new ReceiptBookingException('accounting.receipt_booking.error.entry_already_split');
        }

        $debit = $entry->getDebitAccount();
        $credit = $entry->getCreditAccount();
        if (null !== $credit && self::isPaymentAccount($credit)) {
            return [$entry, \DateTimeImmutable::createFromMutable($entry->getDate()), $credit, true];
        }
        if (null !== $debit && self::isPaymentAccount($debit)) {
            return [$entry, \DateTimeImmutable::createFromMutable($entry->getDate()), $debit, false];
        }

        throw new ReceiptBookingException('accounting.receipt_booking.error.entry_not_a_payment');
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: AccountingAccount, 2: bool}
     */
    private function resolveNewBooking(ReceiptBookingRequest $request): array
    {
        if (null === $request->date || null === $request->paymentAccountNumber) {
            throw new ReceiptBookingException('accounting.receipt_booking.error.payment_account_missing');
        }
        if (!\in_array($request->direction, [ReceiptBookingRequest::DIRECTION_EXPENSE, ReceiptBookingRequest::DIRECTION_INCOME], true)) {
            throw new \LogicException('Unknown direction.');
        }

        $paymentAccount = $this->accountRepository->findByNumber($request->paymentAccountNumber, $this->settingsService->getActivePreset());
        if (!$paymentAccount instanceof AccountingAccount || !self::isPaymentAccount($paymentAccount)) {
            throw new ReceiptBookingException('accounting.receipt_booking.error.payment_account_invalid');
        }

        $batch = $this->batchRepository->findOneBy(['year' => (int) $request->date->format('Y'), 'month' => (int) $request->date->format('n')]);
        if ($batch instanceof BookingBatch) {
            $this->assertBatchOpen($batch);
        }

        return [$request->date, $paymentAccount, ReceiptBookingRequest::DIRECTION_EXPENSE === $request->direction];
    }

    private function resolveLineAccount(string $accountNumber, ?string $preset, int $index): AccountingAccount
    {
        $account = $this->accountRepository->findByNumber(trim($accountNumber), $preset);
        if (!$account instanceof AccountingAccount) {
            throw new ReceiptBookingException('accounting.receipt_booking.error.unknown_account', ['%line%' => $index + 1, '%account%' => $accountNumber]);
        }
        if (self::isPaymentAccount($account)) {
            throw new ReceiptBookingException('accounting.receipt_booking.error.payment_account_as_part', ['%line%' => $index + 1, '%account%' => $accountNumber]);
        }

        return $account;
    }

    private function resolveTaxRate(?string $percent, \DateTimeImmutable $date, ?string $preset, int $index): ?TaxRate
    {
        if (null === $percent) {
            return null;
        }
        if (!is_numeric($percent) || (float) $percent < 0 || (float) $percent > 100) {
            throw new ReceiptBookingException('accounting.receipt_booking.error.tax_rate_invalid', ['%line%' => $index + 1]);
        }

        $taxRate = $this->taxRateRepository->findByRate((float) $percent, $date, $preset);
        if (!$taxRate instanceof TaxRate) {
            throw new ReceiptBookingException('accounting.receipt_booking.error.tax_rate_missing', ['%line%' => $index + 1, '%rate%' => $percent, '%date%' => $date->format('d.m.Y')]);
        }

        return $taxRate;
    }

    /**
     * Entries of the same amount on the same payment account around the date: the payment may
     * already be booked in one piece and should be split instead.
     *
     * @return list<TranslatableMessage>
     */
    private function possibleDuplicates(\DateTimeImmutable $date, string $total, AccountingAccount $paymentAccount): array
    {
        $window = new \DateInterval('P'.self::DUPLICATE_WINDOW_DAYS.'D');
        $warnings = [];
        foreach ($this->entryRepository->findInPeriod($date->sub($window), $date->add($window), $total, $paymentAccount, 5) as $entry) {
            $warnings[] = new TranslatableMessage('accounting.receipt_booking.warning.possible_duplicate', ['%date%' => $entry->getDate()->format('d.m.Y'), '%amount%' => $total, '%account%' => $paymentAccount->getAccountNumber()]);
        }

        return $warnings;
    }

    private function assertBatchOpen(BookingBatch $batch): void
    {
        if ($batch->isClosed()) {
            throw new ReceiptBookingException('accounting.receipt_booking.error.month_closed', ['%month%' => \sprintf('%02d', $batch->getMonth()), '%year%' => $batch->getYear()]);
        }
    }

    private static function isPaymentAccount(AccountingAccount $account): bool
    {
        return $account->isCashAccount() || $account->isBankAccount();
    }

    /** Positive amount with at most two decimals, as integer cents. */
    private static function toCents(string $amount, int $line): int
    {
        if (1 !== preg_match('/^\d{1,11}(\.\d{1,2})?$/', trim($amount))) {
            throw new ReceiptBookingException('accounting.receipt_booking.error.amount_invalid', ['%line%' => $line]);
        }
        [$units, $fraction] = array_pad(explode('.', trim($amount)), 2, '0');
        $cents = (int) $units * 100 + (int) str_pad($fraction, 2, '0');
        if (0 === $cents) {
            throw new ReceiptBookingException('accounting.receipt_booking.error.amount_invalid', ['%line%' => $line]);
        }

        return $cents;
    }

    private static function fromCents(int $cents): string
    {
        return \sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
