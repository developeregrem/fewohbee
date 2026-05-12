<?php

declare(strict_types=1);

namespace App\Payment\Webhook;

class WebhookHandlerRegistry
{
    /** @var array<string, WebhookHandlerInterface> */
    private array $handlersByProviderId = [];

    /** @param iterable<WebhookHandlerInterface> $handlers */
    public function __construct(iterable $handlers)
    {
        foreach ($handlers as $handler) {
            $this->handlersByProviderId[$handler->getProviderId()] = $handler;
        }
    }

    public function get(string $providerId): WebhookHandlerInterface
    {
        if (!isset($this->handlersByProviderId[$providerId])) {
            throw new \InvalidArgumentException(sprintf('No webhook handler registered for provider "%s".', $providerId));
        }

        return $this->handlersByProviderId[$providerId];
    }

    public function has(string $providerId): bool
    {
        return isset($this->handlersByProviderId[$providerId]);
    }
}
