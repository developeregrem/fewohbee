<?php

declare(strict_types=1);

namespace App\Service\BookingJournal\Receipt;

use App\Entity\AccountingAccount;
use App\Entity\BookingEntry;
use App\Entity\TaxRate;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * A validated receipt booking, resolved to entities but not yet written.
 *
 * $paymentOnCredit: the payment account (cash/bank) sits on the credit side, i.e. money went
 * out (an expense); the parts then become the debit accounts. For income it is the other way
 * round.
 */
final class ReceiptBookingPlan
{
    /**
     * @param list<array{account: AccountingAccount, amount: string, taxRate: ?TaxRate}> $parts
     * @param list<TranslatableMessage>                                                 $warnings
     */
    public function __construct(
        public readonly ?BookingEntry $entry,
        public readonly \DateTimeImmutable $date,
        public readonly AccountingAccount $paymentAccount,
        public readonly bool $paymentOnCredit,
        public readonly array $parts,
        public readonly string $total,
        public readonly ?string $invoiceNumber,
        public readonly ?string $remark,
        public readonly array $warnings,
    ) {
    }
}
