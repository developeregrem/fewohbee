<?php

declare(strict_types=1);

namespace App\Service\BookingJournal\Receipt;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** A receipt booking that cannot be carried out as requested, with a message for the user. */
final class ReceiptBookingException extends \InvalidArgumentException implements TranslatableInterface
{
    /**
     * @param array<string, int|string> $parameters
     */
    public function __construct(
        private readonly string $key,
        private readonly array $parameters = [],
    ) {
        parent::__construct($key);
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->key, $this->parameters, null, $locale);
    }
}
