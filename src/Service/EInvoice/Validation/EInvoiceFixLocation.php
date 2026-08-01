<?php

declare(strict_types=1);

namespace App\Service\EInvoice\Validation;

// Where a violation can be fixed by the user.
enum EInvoiceFixLocation: string
{
    case INVOICE = 'invoice';
    case SETTINGS = 'settings';

    // Translation key describing the fix location.
    public function labelKey(): string
    {
        return match ($this) {
            self::INVOICE => 'invoice.einvoice.fixlocation.invoice',
            self::SETTINGS => 'invoice.einvoice.fixlocation.settings',
        };
    }
}
