<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sync;

use App\Entity\CalendarSyncImport;
use App\Exception\IcsFeedException;
use App\Exception\IcsFeedFailure;
use App\Repository\CalendarSyncImportRepository;
use App\Service\Calendar\Sync\Ics\IcsFeedClient;
use App\Service\Calendar\Sync\Ics\IcsOccurrenceReader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Orchestrates remote portal-calendar imports and records their synchronization state.
 */
class ReservationCalendarImportService
{
    public const SYNC_THROTTLE_SECONDS = 3600;

    private const SYNC_THROTTLE_CACHE_KEY = 'calendar_import_sync_all';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CalendarSyncImportRepository $importRepository,
        private readonly IcsFeedClient $feedClient,
        private readonly CacheInterface $cache,
        private readonly TranslatorInterface $translator,
        private readonly IcsOccurrenceReader $occurrenceReader,
        private readonly ImportedReservationSynchronizer $reservationSynchronizer,
    ) {
    }

    /** Run synchronization for one active import whose apartment is active. */
    public function syncImport(CalendarSyncImport $import): void
    {
        if (!$import->isActive() || !$import->getApartment()->isActive()) {
            return;
        }

        try {
            $content = $this->feedClient->fetch($import->getUrl());
        } catch (IcsFeedException $exception) {
            $key = match ($exception->failure) {
                IcsFeedFailure::HttpStatus => 'calendar.sync.import.error.http_status',
                IcsFeedFailure::Unreachable => 'calendar.sync.import.error.unreachable',
                IcsFeedFailure::TooLarge => 'calendar.sync.import.error.too_large',
            };
            $this->updateSyncError($import, $key);

            return;
        }

        if (!$this->occurrenceReader->isValidCalendar($content)) {
            $this->updateSyncError($import, 'calendar.sync.import.error.invalid_ical');

            return;
        }

        try {
            $read = $this->occurrenceReader->readEvents(
                $content,
                new \DateTimeZone(date_default_timezone_get()),
            );
        } catch (\Throwable) {
            $this->updateSyncError($import, 'calendar.sync.import.error.invalid_ical');

            return;
        }

        if (0 === $read->sourceEventCount) {
            $this->updateSyncError($import, 'calendar.sync.import.error.no_events');

            return;
        }

        $missingCount = $read->skipped;
        $conflictCount = 0;
        foreach ($read->occurrences as $event) {
            $outcome = $this->reservationSynchronizer->synchronize($import, $event);
            if (ReservationImportOutcome::MissingRequiredData === $outcome) {
                ++$missingCount;
            } elseif (ReservationImportOutcome::ConflictSkipped === $outcome) {
                ++$conflictCount;
            }
        }

        $import->setLastSyncAt(new \DateTime());
        $import->setLastSyncError(
            0 < $missingCount + $conflictCount
                ? $this->buildSkipSummaryMessage($missingCount, $conflictCount)
                : null,
        );
        $this->entityManager->flush();
    }

    /**
     * Synchronize all active imports once per hour unless a scheduled run forces the window.
     */
    public function syncActiveImports(bool $force = false): void
    {
        if (!$this->claimSyncWindow($force)) {
            return;
        }

        foreach ($this->importRepository->findBy(['isActive' => true]) as $import) {
            $this->syncImport($import);
        }
    }

    /** Store a translated error consistently with translated skip summaries. */
    private function updateSyncError(CalendarSyncImport $import, string $errorKey): void
    {
        $import->setLastSyncAt(new \DateTime());
        $import->setLastSyncError($this->translator->trans($errorKey));
        $this->entityManager->flush();
    }

    /** Build a localized summary for structurally invalid and conflicting VEVENTs. */
    private function buildSkipSummaryMessage(int $missingCount, int $conflictCount): string
    {
        return $this->translator->trans('calendar.sync.import.error.skipped.summary', [
            '%missing%' => $missingCount,
            '%conflict%' => $conflictCount,
        ]);
    }

    /**
     * Claim the shared sync window; forced scheduled runs always proceed and refresh it.
     */
    private function claimSyncWindow(bool $force): bool
    {
        $claimed = false;
        $this->cache->get(self::SYNC_THROTTLE_CACHE_KEY, function (ItemInterface $item) use (&$claimed): bool {
            $item->expiresAfter(self::SYNC_THROTTLE_SECONDS);
            $claimed = true;

            return true;
        }, $force ? INF : 0.0);

        return $force || $claimed;
    }
}
