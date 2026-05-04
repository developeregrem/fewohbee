<?php

declare(strict_types=1);

namespace App\Dto\BookingJournal\BankImport;

/**
 * Thrown when several files in one upload describe different source IBANs and
 * therefore cannot be merged into a single import draft.
 */
final class MultipleSourceAccountsException extends \RuntimeException
{
}
