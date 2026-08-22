<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ReservationStatus;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Resolves the display name of records whose label may be owned by the
 * application rather than by the user.
 *
 * A system reservation status was inserted with German text hardcoded in a
 * migration, so installations running in English showed German labels with no
 * way to correct them — the edit form is disabled for system statuses. Its
 * label is therefore derived from the immutable `code` at render time, which
 * means existing installations pick up the translation without any data
 * migration, whatever literal text their name column holds.
 *
 * User-created statuses are returned verbatim: their name belongs to the user.
 */
final class DisplayNameResolver
{
    private const STATUS_KEY_PREFIX = 'status.';

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Display name for a status, in the active locale for system statuses.
     *
     * Falls back to the stored name when the key has no translation, so a
     * newly added system code can never leak a raw key into the UI.
     */
    public function resolve(ReservationStatus $status): string
    {
        if (!$status->isSystem()) {
            return (string) $status->getName();
        }

        $key = self::STATUS_KEY_PREFIX.$status->getCode();
        $translated = $this->translator->trans($key);

        // Symfony returns the key unchanged when it cannot resolve it.
        return $translated === $key ? (string) $status->getName() : $translated;
    }

    /**
     * Sort statuses by their display name, so lists stay alphabetical in the
     * language the user sees. Ordering by the stored name in SQL would order a
     * system status by the text frozen into the migration instead.
     *
     * @param ReservationStatus[] $statuses
     *
     * @return ReservationStatus[] reindexed from 0
     */
    public function sortByDisplayName(array $statuses): array
    {
        usort(
            $statuses,
            fn (ReservationStatus $a, ReservationStatus $b) => $this->resolve($a) <=> $this->resolve($b),
        );

        return $statuses;
    }
}
