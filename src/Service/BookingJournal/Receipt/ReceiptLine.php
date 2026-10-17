<?php

declare(strict_types=1);

namespace App\Service\BookingJournal\Receipt;

/**
 * One part of a receipt: the gross amount booked on one account at one VAT rate.
 *
 * $amount is a decimal string with two places ("12.34"), as BookingEntry stores it.
 * $taxRatePercent null means no VAT rate on the entry (e.g. postage, fees).
 */
final class ReceiptLine
{
    public function __construct(
        public readonly string $accountNumber,
        public readonly string $amount,
        public readonly ?string $taxRatePercent = null,
    ) {
    }
}
