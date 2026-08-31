<?php

declare(strict_types=1);

namespace App\Exception;

/** Reports a translatable calendar synchronization failure. */
class CalendarSyncException extends \RuntimeException
{
    /**
     * @param array<string, string|int> $translationParameters
     */
    public function __construct(
        public readonly string $translationKey,
        public readonly array $translationParameters = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($translationKey, previous: $previous);
    }
}
