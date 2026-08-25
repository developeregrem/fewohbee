<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * Holds every tagged notification provider, keyed by its identifier.
 *
 * Mirrors WorkflowActionRegistry so both extension points read the same way.
 */
class NotificationProviderRegistry
{
    /** @var array<string, NotificationProviderInterface> */
    private array $providersByKey = [];

    /** @param iterable<NotificationProviderInterface> $providers */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->providersByKey[$provider->getKey()] = $provider;
        }
    }

    public function has(string $key): bool
    {
        return isset($this->providersByKey[$key]);
    }

    public function get(string $key): NotificationProviderInterface
    {
        if (!isset($this->providersByKey[$key])) {
            throw new \InvalidArgumentException(sprintf('Unknown notification provider "%s".', $key));
        }

        return $this->providersByKey[$key];
    }

    /** @return array<string, NotificationProviderInterface> */
    public function all(): array
    {
        return $this->providersByKey;
    }
}
