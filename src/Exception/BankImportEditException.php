<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Carries the JSON error code and HTTP status that the bank-import edit
 * endpoints return on failure. The matching subscriber renders any of these
 * as a JsonResponse so the resolver and controllers can use plain throws
 * instead of returning early with hand-crafted payloads.
 */
final class BankImportEditException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
    ) {
        parent::__construct($errorCode);
    }

    public static function invalidToken(): self
    {
        return new self('invalid_token', 403);
    }

    public static function draftNotFound(): self
    {
        return new self('draft_not_found', 404);
    }

    public static function lineNotFound(): self
    {
        return new self('line_not_found', 404);
    }

    public static function lineReadonly(): self
    {
        return new self('line_readonly', 409);
    }
}
