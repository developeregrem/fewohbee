<?php

declare(strict_types=1);

namespace App\Payment\Enum;

enum PaymentIntent: string
{
    case PAYMENT = 'payment';
    case AUTHORIZATION = 'authorization';
}
