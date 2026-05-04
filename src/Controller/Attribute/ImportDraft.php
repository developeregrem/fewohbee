<?php

declare(strict_types=1);

namespace App\Controller\Attribute;

/**
 * Marks a controller argument as a bank-import draft to be loaded from the
 * session by {@see \App\Controller\Resolver\ImportDraftResolver}.
 *
 * The resolver pulls the {sessionImportId} route parameter, validates the
 * "_token" CSRF token (id "bank_import_line_<sessionImportId>") and returns
 * the {@see \App\Dto\BookingJournal\BankImport\ImportState}. On failure it
 * throws a {@see \App\Exception\BankImportEditException} which is
 * rendered as a JSON response by the matching exception subscriber.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class ImportDraft
{
}
