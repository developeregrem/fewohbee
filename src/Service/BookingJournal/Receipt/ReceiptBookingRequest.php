<?php

declare(strict_types=1);

namespace App\Service\BookingJournal\Receipt;

/**
 * Booking of one receipt that is spread over several accounts and VAT rates.
 *
 * Two modes:
 * - split an existing entry ($entryId set): the payment was already booked in one piece, e.g.
 *   by the bank statement import; the entry becomes the first part and keeps its date, payment
 *   account and import fingerprint;
 * - book anew ($entryId null): for payments no import brings in, typically cash. Then $date,
 *   $paymentAccountNumber and $direction are required.
 */
final class ReceiptBookingRequest
{
    public const DIRECTION_EXPENSE = 'expense';
    public const DIRECTION_INCOME = 'income';

    /**
     * @param list<ReceiptLine> $lines
     */
    public function __construct(
        public readonly array $lines,
        public readonly ?int $entryId = null,
        public readonly ?\DateTimeImmutable $date = null,
        public readonly ?string $paymentAccountNumber = null,
        public readonly string $direction = self::DIRECTION_EXPENSE,
        public readonly ?string $invoiceNumber = null,
        public readonly ?string $remark = null,
    ) {
    }
}
