<?php

declare(strict_types=1);

namespace App\Payment\Provider;

use App\Payment\Exception\PaymentProviderException;

class PaymentProviderRegistry
{
    /** @var array<string, PaymentProviderInterface> */
    private array $providersById = [];

    /** @param iterable<PaymentProviderInterface> $providers */
    public function __construct(
        iterable $providers,
        private readonly ?string $activeProviderId = null,
    ) {
        foreach ($providers as $provider) {
            $this->providersById[$provider->getId()] = $provider;
        }
    }

    public function get(string $id): PaymentProviderInterface
    {
        if (!isset($this->providersById[$id])) {
            throw new \InvalidArgumentException(sprintf('Unknown payment provider "%s".', $id));
        }

        return $this->providersById[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->providersById[$id]);
    }

    /** @return array<string, PaymentProviderInterface> */
    public function all(): array
    {
        return $this->providersById;
    }

    public function getActive(): PaymentProviderInterface
    {
        if (null === $this->activeProviderId || '' === $this->activeProviderId) {
            throw new PaymentProviderException('No active payment provider configured. Set PAYMENT_PROVIDER in .env.');
        }

        if (!isset($this->providersById[$this->activeProviderId])) {
            throw new PaymentProviderException(sprintf(
                'Configured active payment provider "%s" is not registered. Available: %s',
                $this->activeProviderId,
                implode(', ', array_keys($this->providersById)) ?: '(none)'
            ));
        }

        return $this->providersById[$this->activeProviderId];
    }
}
