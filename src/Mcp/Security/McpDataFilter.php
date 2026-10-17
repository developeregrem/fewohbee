<?php

declare(strict_types=1);

namespace App\Mcp\Security;

use App\Security\Voter\ApiScopeVoter;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Data minimisation for MCP output. Everything a tool returns is sent to the AI provider, so
 * guest names, contact details and free text leave the installation only when the token carries
 * guests:read (and its owner the matching role).
 *
 * Free text (remarks, notes) may have been written by guests and can try to instruct the model
 * (prompt injection). It is therefore wrapped as {"untrusted_text": ...} and truncated; the server
 * instructions tell the model to treat such fields as data.
 */
class McpDataFilter
{
    private const DEFAULT_TEXT_LIMIT = 500;

    private ?bool $guestDataAllowed = null;

    public function __construct(
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public function mayShareGuestData(): bool
    {
        return $this->guestDataAllowed ??= $this->authorizationChecker->isGranted(ApiScopeVoter::GUESTS_READ);
    }

    /** A personal value (name, email, phone, ...) or null when guest data must not be shared. */
    public function personal(?string $value): ?string
    {
        if (!$this->mayShareGuestData() || null === $value || '' === trim($value)) {
            return null;
        }

        return $value;
    }

    /**
     * Free text as {"untrusted_text": ...}, or null when empty or guest data must not be shared.
     *
     * @return array{untrusted_text: string, truncated: bool}|null
     */
    public function untrustedText(?string $text, int $limit = self::DEFAULT_TEXT_LIMIT): ?array
    {
        return $this->mayShareGuestData() ? self::wrapUntrusted($text, $limit) : null;
    }

    /**
     * Free text as {"untrusted_text": ...} without the guest data check, for tools whose own scope
     * already covers sharing it (e.g. bank statement purposes under bank-import:write).
     *
     * @return array{untrusted_text: string, truncated: bool}|null
     */
    public static function wrapUntrusted(?string $text, int $limit = self::DEFAULT_TEXT_LIMIT): ?array
    {
        if (null === $text) {
            return null;
        }

        $text = trim(strip_tags($text));
        if ('' === $text) {
            return null;
        }

        $truncated = mb_strlen($text) > $limit;

        return [
            'untrusted_text' => $truncated ? mb_substr($text, 0, $limit) : $text,
            'truncated' => $truncated,
        ];
    }
}
