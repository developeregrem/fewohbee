<?php

declare(strict_types=1);

namespace App\Payment\Dto;

use App\Payment\Enum\PaymentStatus;

final readonly class PaymentStatusSnapshot
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public PaymentStatus $status,
        public array $raw = [],
    ) {
    }
}
