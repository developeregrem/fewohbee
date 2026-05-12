<?php

declare(strict_types=1);

namespace App\Payment\Enum;

enum ProviderCapability: string
{
    case ONLINE_PAYMENT = 'online_payment';
    case DIRECT_DEBIT = 'direct_debit';
    case CARD_PREAUTH = 'card_preauth';
    case REFUND = 'refund';
}
