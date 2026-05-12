<?php

declare(strict_types=1);

namespace App\Payment\Enum;

/**
 * Caller-provided classification of a payment transaction. The Payment core
 * does not interpret these values — they exist so application layers
 * (booking, accounting, reporting) can group multiple transactions per
 * booking (e.g. deposit + balance).
 */
enum PaymentKind: string
{
    case DEPOSIT = 'deposit';
    case BALANCE = 'balance';
    case FULL = 'full';
}
