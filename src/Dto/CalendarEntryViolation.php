<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * One reason a calendar entry cannot be saved, named per form field.
 *
 * Returned by CalendarEntryService instead of a raw message so the rules stay
 * testable without a form and without the translator: the controller is left
 * with mapping $field to a form child and translating $messageKey.
 */
final readonly class CalendarEntryViolation
{
    /**
     * @param string                $field      name of the form child the message belongs on
     * @param string                $messageKey translation key, in the default message domain
     * @param array<string, string> $parameters placeholders of $messageKey, e.g. %max%
     */
    public function __construct(
        public string $field,
        public string $messageKey,
        public array $parameters = [],
    ) {
    }
}
