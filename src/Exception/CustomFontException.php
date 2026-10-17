<?php

declare(strict_types=1);

namespace App\Exception;

/** Carries a safe translation key for an expected custom-font upload failure. */
final class CustomFontException extends \RuntimeException
{
    public function __construct(
        public readonly string $translationKey,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($translationKey, previous: $previous);
    }
}
