<?php

declare(strict_types=1);

namespace App\Payment\Dto;

final readonly class PaymentInitiation
{
    public function __construct(
        public string $providerPaymentId,
        public ?string $redirectUrl,
    ) {
    }
}
