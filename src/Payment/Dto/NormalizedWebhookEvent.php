<?php

declare(strict_types=1);

namespace App\Payment\Dto;

use App\Payment\Enum\WebhookEventType;

final readonly class NormalizedWebhookEvent
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public WebhookEventType $type,
        public string $providerPaymentId,
        public ?float $amount = null,
        public array $raw = [],
    ) {
    }
}
