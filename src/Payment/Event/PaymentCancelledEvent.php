<?php

declare(strict_types=1);

namespace App\Payment\Event;

use App\Entity\PaymentTransaction;

class PaymentCancelledEvent
{
    public function __construct(
        public readonly PaymentTransaction $transaction,
    ) {
    }
}
