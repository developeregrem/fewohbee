<?php

declare(strict_types=1);

namespace App\Service\BookingJournal\BankImport;

use App\Dto\BookingJournal\BankImport\ImportState;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Thin wrapper around the Symfony session that holds in-progress bank statement
 * imports per user. The state never touches disk on its own — Symfony's session
 * handler decides where to put it (native file, redis, …).
 *
 * Key shape in the session:
 *   bank_import.drafts: array<string sessionImportId, array state>
 */
final class BankImportDraftSession
{
    public const SESSION_KEY = 'bank_import.drafts';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    public function create(ImportState $state): string
    {
        if ('' === $state->sessionImportId) {
            $state->sessionImportId = Uuid::v4()->toRfc4122();
        }

        $this->save($state);

        return $state->sessionImportId;
    }

    public function load(string $sessionImportId): ?ImportState
    {
        $drafts = $this->session()->get(self::SESSION_KEY, []);
        if (!isset($drafts[$sessionImportId])) {
            return null;
        }

        return ImportState::fromArray($drafts[$sessionImportId]);
    }

    public function save(ImportState $state): void
    {
        $session = $this->session();
        $drafts = $session->get(self::SESSION_KEY, []);
        $drafts[$state->sessionImportId] = $state->toArray();
        $session->set(self::SESSION_KEY, $drafts);
    }

    public function discard(string $sessionImportId): void
    {
        $session = $this->session();
        $drafts = $session->get(self::SESSION_KEY, []);
        unset($drafts[$sessionImportId]);
        $session->set(self::SESSION_KEY, $drafts);
    }

    /**
     * @return list<ImportState>
     */
    public function list(): array
    {
        $drafts = $this->session()->get(self::SESSION_KEY, []);
        $result = [];
        foreach ($drafts as $entry) {
            $result[] = ImportState::fromArray($entry);
        }

        return $result;
    }

    private function session(): \Symfony\Component\HttpFoundation\Session\SessionInterface
    {
        $request = $this->requestStack->getMainRequest();
        if (null === $request || !$request->hasSession()) {
            throw new \LogicException($this->trans('accounting.bank_import.draft.session_required'));
        }

        return $request->getSession();
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator?->trans($key, $parameters) ?? $key;
    }
}
