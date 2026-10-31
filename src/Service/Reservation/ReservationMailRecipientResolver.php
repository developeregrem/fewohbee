<?php

declare(strict_types=1);

namespace App\Service\Reservation;

use App\Entity\Customer;
use App\Entity\Invoice;
use App\Entity\Reservation;

/**
 * Picks the recipient of a mail written from the reservation correspondence: the booker's
 * email at first, and the invoice's email once an invoice is attached, so a guest with a
 * separate invoice address gets the invoice there.
 */
final class ReservationMailRecipientResolver
{
    /**
     * The booker's email of the first reservation; with several addresses the last one that
     * has an email wins. Empty when there is none.
     *
     * @param list<Reservation> $reservations
     */
    public function defaultRecipient(array $reservations): string
    {
        $booker = ($reservations[0] ?? null)?->getBooker();
        if (!$booker instanceof Customer) {
            return '';
        }

        $recipient = '';
        foreach ($booker->getCustomerAddresses() as $address) {
            $email = trim((string) $address->getEmail());
            if ('' !== $email) {
                $recipient = $email;
            }
        }

        return $recipient;
    }

    /**
     * The recipient to use after the invoice was attached, or null to keep the current one.
     * Only a recipient that is still empty or the prefilled booker email is replaced; one the
     * user typed in is never overwritten.
     *
     * @param list<Reservation> $reservations
     */
    public function recipientForAttachedInvoice(string $currentRecipient, Invoice $invoice, array $reservations): ?string
    {
        $invoiceEmail = trim((string) $invoice->getEmail());
        $current = trim($currentRecipient);
        if ('' === $invoiceEmail || 0 === strcasecmp($current, $invoiceEmail)) {
            return null;
        }
        if ('' !== $current && 0 !== strcasecmp($current, $this->defaultRecipient($reservations))) {
            return null;
        }

        return $invoiceEmail;
    }
}
