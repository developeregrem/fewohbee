<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sync;

use App\Dto\CalendarSync\CalendarImportFilterSet;
use App\Entity\CalendarSyncImport;
use App\Repository\CalendarSyncImportRepository;

/**
 * Reuses user-confirmed calendar-label filters between room imports from the same portal host.
 */
final class CalendarImportFilterSharingService
{
    public function __construct(
        private readonly CalendarSyncImportRepository $calendarSyncImportRepository,
    ) {
    }

    /**
     * Reuse an existing same-portal configuration for a new room or share its newly chosen filters.
     *
     * Existing imports are mutated when the new import already contains a user-confirmed filter.
     */
    public function reuseForNewImport(CalendarSyncImport $import): void
    {
        if (!$import->getShareSummaryFilters()) {
            return;
        }

        $otherImports = $this->findOtherImportsForSamePortal($import);

        if ([] === $import->getExcludedSummaries() && [] === $import->getExcludedSummaryTerms()) {
            foreach ($otherImports as $otherImport) {
                if ([] !== $otherImport->getExcludedSummaries() || [] !== $otherImport->getExcludedSummaryTerms()) {
                    $this->copyFilters($otherImport, $import);

                    return;
                }
            }

            return;
        }

        foreach ($otherImports as $otherImport) {
            $this->copyFilters($import, $otherImport);
        }
    }

    /**
     * Apply an edited import's filters to every opted-in room import on the same portal host.
     *
     * The related managed entities are mutated and persisted by the caller's next Doctrine flush.
     */
    public function shareFromExistingImport(CalendarSyncImport $import): void
    {
        if (!$import->getShareSummaryFilters()) {
            return;
        }

        foreach ($this->findOtherImportsForSamePortal($import) as $otherImport) {
            $this->copyFilters($import, $otherImport);
        }
    }

    /** Return same-portal filters for a calendar preview, or null when no filters are configured. */
    public function findReusableFilterSet(string $calendarUrl): ?CalendarImportFilterSet
    {
        $portalHost = $this->normalizePortalHost($calendarUrl);
        if (null === $portalHost) {
            return null;
        }

        $imports = $this->calendarSyncImportRepository->findSummaryFilterSharingImports();
        foreach ($imports as $import) {
            if ($portalHost !== $this->normalizePortalHost($import->getUrl())) {
                continue;
            }

            if ([] !== $import->getExcludedSummaries() || [] !== $import->getExcludedSummaryTerms()) {
                return CalendarImportFilterSet::fromImport($import);
            }
        }

        return null;
    }

    /**
     * Return opted-in imports whose configured calendar URL belongs to the same portal host.
     *
     * @return list<CalendarSyncImport>
     */
    private function findOtherImportsForSamePortal(CalendarSyncImport $import): array
    {
        $portalHost = $this->normalizePortalHost($import->getUrl());
        if (null === $portalHost) {
            return [];
        }

        return array_values(array_filter(
            $this->calendarSyncImportRepository->findSummaryFilterSharingImports($import),
            fn (CalendarSyncImport $candidate): bool => $portalHost
                === $this->normalizePortalHost($candidate->getUrl()),
        ));
    }

    /**
     * Extract the stable provider part of a calendar URL without its unique path or secret query.
     *
     * Hostnames are case-insensitive; a conventional leading "www." and trailing dot are ignored.
     */
    private function normalizePortalHost(string $calendarUrl): ?string
    {
        $host = parse_url(trim($calendarUrl), PHP_URL_HOST);
        if (!is_string($host)) {
            return null;
        }

        $host = mb_strtolower(rtrim($host, '.'));
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return '' === $host ? null : $host;
    }

    /** Copy only the reusable portal-label rules, never room-specific import settings. */
    private function copyFilters(CalendarSyncImport $source, CalendarSyncImport $target): void
    {
        $target
            ->setExcludedSummaries($source->getExcludedSummaries())
            ->setExcludedSummaryTerms($source->getExcludedSummaryTerms());
    }
}
