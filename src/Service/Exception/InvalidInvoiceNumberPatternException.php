<?php

declare(strict_types=1);

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Exception;

/**
 * Thrown when an invoice number pattern cannot be compiled, e.g. because it has no
 * <number> placeholder or uses an unknown one.
 *
 * Carries the translation key (domain `validators`) and its placeholder parameters so
 * callers can render the message without re-deriving the cause.
 */
final class InvalidInvoiceNumberPatternException extends \InvalidArgumentException
{
    /**
     * @param array<string, string> $translationParameters
     */
    public function __construct(
        private readonly string $translationKey,
        private readonly array $translationParameters = [],
    ) {
        parent::__construct($translationKey);
    }

    public function getTranslationKey(): string
    {
        return $this->translationKey;
    }

    /**
     * @return array<string, string>
     */
    public function getTranslationParameters(): array
    {
        return $this->translationParameters;
    }
}
