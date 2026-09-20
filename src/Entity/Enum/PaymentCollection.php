<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Who takes the guest's money for a booking: the portal it came through, or the
 * house itself.
 *
 * It decides what a portal's payment fee is charged on - a portal charges it for
 * processing a payment, so an amount it never handled carries none. Asked twice
 * on a reservation origin, because the answer can differ per amount: a portal
 * may collect the stay while the tourist tax is paid on arrival.
 *
 * An enum rather than a boolean on purpose. A portal collecting only part of the
 * amount - a prepayment through the portal, the rest at the property - is a case
 * that exists and is not covered here (it needs a figure, not a flag). When it
 * is, it becomes another case plus a nullable amount rather than a migration
 * away from a boolean.
 */
enum PaymentCollection: string
{
    /** The portal processed the payment and charges its fee on it. */
    case PORTAL = 'portal';

    /** The house was paid directly, so no portal handled the money. */
    case PROPERTY = 'property';

    public function isPortal(): bool
    {
        return self::PORTAL === $this;
    }
}
