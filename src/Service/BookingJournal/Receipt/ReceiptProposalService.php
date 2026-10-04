<?php

declare(strict_types=1);

namespace App\Service\BookingJournal\Receipt;

use App\Entity\AccountingAccount;
use App\Entity\BookingEntry;
use App\Entity\ReceiptProposal;
use App\Entity\User;
use App\Repository\AccountingAccountRepository;
use App\Repository\BookingEntryRepository;
use App\Repository\ReceiptProposalRepository;
use App\Repository\TaxRateRepository;
use App\Service\BookingJournal\AccountingSettingsService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Receipts handed in by AI assistants: taking them in, finding their payment, booking them.
 *
 * The assistant only submits what it read from the receipt. It never sees the journal: which
 * payment belongs to the receipt is worked out here, from amount and date, and shown to the
 * person who books. Nothing reaches the journal before that person confirmed.
 *
 * Messages of \InvalidArgumentException thrown by submit() go back to the assistant and are
 * therefore in English.
 */
class ReceiptProposalService
{
    public const MAX_LINES = ReceiptBookingService::MAX_LINES;
    /** Card payments reach the bank account a few days after the purchase. */
    private const PAYMENT_DAYS_BEFORE = 3;
    private const PAYMENT_DAYS_AFTER = 14;
    private const MAX_CANDIDATES = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReceiptProposalRepository $proposalRepository,
        private readonly BookingEntryRepository $entryRepository,
        private readonly AccountingAccountRepository $accountRepository,
        private readonly TaxRateRepository $taxRateRepository,
        private readonly AccountingSettingsService $settingsService,
        private readonly ReceiptBookingService $bookingService,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Stores a receipt as an open proposal. A repeated submission with the same supplier, date,
     * total and printed receipt number returns the existing open proposal. Unnumbered receipts
     * cannot safely be distinguished by supplier, day and amount alone.
     *
     * @param list<array{text: ?string, amount: string, taxRate: ?string, accountNumber: ?string}> $lines
     *
     * @return array{0: ReceiptProposal, 1: bool} the proposal and whether it existed already
     *
     * @throws \InvalidArgumentException with a message for the assistant
     */
    public function submit(
        string $supplier,
        \DateTimeImmutable $date,
        ?string $receiptNumber,
        string $total,
        string $payment,
        array $lines,
        ?string $note,
        ?User $submittedBy,
        ?string $tokenPrefix,
    ): array {
        $supplier = trim($supplier);
        if ('' === $supplier || mb_strlen($supplier) > 150) {
            throw new \InvalidArgumentException("'supplier' must be 1 to 150 characters.");
        }
        if (!\in_array($payment, ReceiptProposal::PAYMENTS, true)) {
            throw new \InvalidArgumentException("'payment' must be 'bank', 'cash' or 'unknown'.");
        }
        if ([] === $lines || \count($lines) > self::MAX_LINES) {
            throw new \InvalidArgumentException(\sprintf('A receipt needs between 1 and %d lines.', self::MAX_LINES));
        }

        $preset = $this->settingsService->getActivePreset();
        $sum = 0;
        foreach ($lines as $index => $line) {
            $sum += self::cents($line['amount']);
            if (null !== $line['taxRate'] && null === $this->taxRateRepository->findByRate((float) $line['taxRate'], $date, $preset)) {
                throw new \InvalidArgumentException(\sprintf('lines[%d]: no %s %% VAT rate is set up for %s. Call list_tax_rates.', $index, $line['taxRate'], $date->format('Y-m-d')));
            }
            if (null !== $line['accountNumber']) {
                $account = $this->accountRepository->findByNumber($line['accountNumber'], $preset);
                if (!$account instanceof AccountingAccount) {
                    throw new \InvalidArgumentException(\sprintf('lines[%d]: unknown account %s. Call list_accounting_accounts, or leave accountNumber empty.', $index, $line['accountNumber']));
                }
                if ($account->isCashAccount() || $account->isBankAccount()) {
                    throw new \InvalidArgumentException(\sprintf('lines[%d]: %s is a cash or bank account; suggest the account of what was bought.', $index, $line['accountNumber']));
                }
            }
        }
        if ($sum !== self::cents($total)) {
            throw new \InvalidArgumentException(\sprintf('The lines add up to %s, but the total is %s. Combine the positions per account and VAT rate with discounts already deducted.', self::fromCents($sum), $total));
        }

        // Supplier, day and amount alone are not a receipt identity: two purchases can have
        // exactly the same amount on the same day. Without a printed number, keep both proposals.
        $existing = null !== $receiptNumber
            ? $this->proposalRepository->findOpenDuplicate($supplier, $date, $total, $receiptNumber)
            : null;
        if ($existing instanceof ReceiptProposal) {
            return [$existing, true];
        }

        $proposal = new ReceiptProposal($supplier, $date, $receiptNumber, $total, $payment, $lines, $note, $submittedBy, $tokenPrefix, $this->clock->now());
        $this->em->persist($proposal);
        $this->em->flush();

        return [$proposal, false];
    }

    /**
     * Journal entries that may be the payment of the receipt: same amount against a cash or bank
     * account around the receipt date, still booked in one piece and in an open month. Entries on
     * the account the receipt names (bank or cash) come first, the closest date before others.
     *
     * @return list<BookingEntry>
     */
    public function paymentCandidates(ReceiptProposal $proposal): array
    {
        $date = $proposal->getReceiptDate();
        $entries = $this->entryRepository->findInPeriod(
            $date->modify('-'.self::PAYMENT_DAYS_BEFORE.' days'),
            $date->modify('+'.self::PAYMENT_DAYS_AFTER.' days'),
            $proposal->getTotal(),
            null,
            50,
        );

        $candidates = array_values(array_filter($entries, static function (BookingEntry $entry): bool {
            return null === $entry->getSplitGroupUuid()
                && null === $entry->getInvoiceId()
                && !$entry->isOpeningBalance()
                && !$entry->getBookingBatch()->isClosed()
                && null !== self::paymentAccount($entry);
        }));

        $preferred = match ($proposal->getPayment()) {
            ReceiptProposal::PAYMENT_BANK => static fn (AccountingAccount $account): bool => $account->isBankAccount(),
            ReceiptProposal::PAYMENT_CASH => static fn (AccountingAccount $account): bool => $account->isCashAccount(),
            default => static fn (AccountingAccount $account): bool => true,
        };
        usort($candidates, static function (BookingEntry $a, BookingEntry $b) use ($preferred, $date): int {
            return [!$preferred(self::paymentAccount($a)), abs($a->getDate()->getTimestamp() - $date->getTimestamp())]
                <=> [!$preferred(self::paymentAccount($b)), abs($b->getDate()->getTimestamp() - $date->getTimestamp())];
        });

        return \array_slice($candidates, 0, self::MAX_CANDIDATES);
    }

    /**
     * Entries that suggest the receipt is booked already, e.g. split by hand when its bank
     * statement was imported. paymentCandidates() only finds a payment still booked in one
     * piece; without this, such a receipt would look unbooked and be booked a second time.
     *
     * Two signs, both around the receipt date and against a cash or bank account:
     * - the booking text names the supplier ("Philipps" for "Thomas Philipps");
     * - several entries of one split group, or of one day and payment account, add up to the
     *   receipt total.
     *
     * A payment offered for splitting is left out even when it names the supplier: splitting it
     * is exactly what should happen.
     *
     * @return list<BookingEntry> ordered by date
     */
    public function possiblyBookedAlready(ReceiptProposal $proposal): array
    {
        $date = $proposal->getReceiptDate();
        $entries = array_filter(
            $this->entryRepository->findInPeriod(
                $date->modify('-'.self::PAYMENT_DAYS_BEFORE.' days'),
                $date->modify('+'.self::PAYMENT_DAYS_AFTER.' days'),
                null,
                null,
                500,
            ),
            static fn (BookingEntry $entry): bool => !$entry->isOpeningBalance() && null !== self::paymentAccount($entry),
        );

        $toSplit = array_map(static fn (BookingEntry $entry): ?int => $entry->getId(), $this->paymentCandidates($proposal));
        $entries = array_filter($entries, static fn (BookingEntry $entry): bool => !\in_array($entry->getId(), $toSplit, true));

        $found = [];
        $words = self::supplierWords($proposal->getSupplier());
        foreach ($entries as $entry) {
            $remark = mb_strtolower((string) $entry->getRemark());
            foreach ($words as $word) {
                if (str_contains($remark, $word)) {
                    $found[(int) $entry->getId()] = $entry;
                    break;
                }
            }
        }

        $groups = [];
        foreach ($entries as $entry) {
            $key = $entry->getSplitGroupUuid() ?? $entry->getDate()->format('Y-m-d').'|'.self::paymentAccount($entry)?->getId();
            $groups[$key][] = $entry;
        }
        $total = self::cents($proposal->getTotal());
        foreach ($groups as $group) {
            $sum = array_sum(array_map(static fn (BookingEntry $entry): int => self::cents($entry->getAmount()), $group));
            // One entry of the full amount is a payment to split, offered by paymentCandidates().
            if (\count($group) > 1 && $sum === $total) {
                foreach ($group as $entry) {
                    $found[(int) $entry->getId()] = $entry;
                }
            }
        }

        $found = array_values($found);
        usort($found, static fn (BookingEntry $a, BookingEntry $b): int => [$a->getDate(), $a->getId()] <=> [$b->getDate(), $b->getId()]);

        return $found;
    }

    /**
     * Books the proposal as the person confirmed it and marks it booked.
     *
     * @return list<BookingEntry>
     *
     * @throws ReceiptBookingException when the booking is not possible as requested
     */
    public function book(ReceiptProposal $proposal, ReceiptBookingRequest $request, ?User $user): array
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            // Refresh under the row lock: another reviewer may have decided this proposal after
            // the controller loaded it. The journal entry is locked too before it is split.
            $this->em->refresh($proposal, LockMode::PESSIMISTIC_WRITE);
            if (!$proposal->isOpen()) {
                throw new ReceiptBookingException('accounting.receipt_proposal.error.not_open');
            }
            if (null !== $request->entryId) {
                $entry = $this->entryRepository->find($request->entryId);
                if ($entry instanceof BookingEntry) {
                    $this->em->refresh($entry, LockMode::PESSIMISTIC_WRITE);
                }
            }

            $entries = $this->bookingService->book($this->bookingService->plan($request));
            $proposal->markBooked(array_map(static fn (BookingEntry $entry): int => (int) $entry->getId(), $entries), $user, $this->clock->now());
            $this->em->flush();
            $connection->commit();

            return $entries;
        } catch (\Throwable $error) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $error;
        }
    }

    public function discard(ReceiptProposal $proposal, ?User $user): void
    {
        $this->em->wrapInTransaction(function () use ($proposal, $user): void {
            $this->em->refresh($proposal, LockMode::PESSIMISTIC_WRITE);
            if ($proposal->isOpen()) {
                $proposal->discard($user, $this->clock->now());
            }
        });
    }

    /** The cash or bank side of an entry, or null when it touches neither. */
    public static function paymentAccount(BookingEntry $entry): ?AccountingAccount
    {
        foreach ([$entry->getCreditAccount(), $entry->getDebitAccount()] as $account) {
            if (null !== $account && ($account->isCashAccount() || $account->isBankAccount())) {
                return $account;
            }
        }

        return null;
    }

    /**
     * Distinctive words of a supplier name, lower case: "Thomas Philipps GmbH & Co. KG" gives
     * "thomas" and "philipps". Short words and legal forms would match too much.
     *
     * @return list<string>
     */
    private static function supplierWords(string $supplier): array
    {
        $ignored = ['gmbh', 'mbh', 'co.kg', 'ohg', 'e.k.', 'ug', 'ag', 'und', 'markt', 'filiale'];
        $words = preg_split('/[\s,&\/()-]+/u', mb_strtolower($supplier)) ?: [];

        return array_values(array_filter($words, static fn (string $word): bool => mb_strlen($word) >= 4 && !\in_array($word, $ignored, true)));
    }

    private static function cents(string $amount): int
    {
        if (1 !== preg_match('/^\d{1,11}(\.\d{1,2})?$/', $amount)) {
            throw new \InvalidArgumentException('Amounts must be positive with at most two decimals.');
        }
        [$units, $fraction] = array_pad(explode('.', $amount), 2, '0');
        $cents = (int) $units * 100 + (int) str_pad($fraction, 2, '0');
        if (0 === $cents) {
            throw new \InvalidArgumentException('Amounts must be positive.');
        }

        return $cents;
    }

    private static function fromCents(int $cents): string
    {
        return \sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
