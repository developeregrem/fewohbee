<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sync;

use App\Entity\CalendarSyncImport;

/**
 * Applies one import's user-confirmed calendar-label exclusions to portal events.
 */
final class CalendarImportSummaryMatcher
{
    public const EMPTY_SUMMARY_FILTER = '__fewohbee_empty_summary__';

    /** Return whether the event label is excluded by an exact or partial rule. */
    public function isExcluded(CalendarSyncImport $import, string $summary): bool
    {
        $exactFilterValue = $this->exactFilterValue($summary);

        foreach ($import->getExcludedSummaries() as $excludedSummary) {
            if ($exactFilterValue === $this->exactFilterValue($excludedSummary)) {
                return true;
            }
        }

        $normalizedSummary = $this->normalize($summary);
        foreach ($import->getExcludedSummaryTerms() as $term) {
            $normalizedTerm = $this->normalize($term);
            if ('' !== $normalizedTerm && str_contains($normalizedSummary, $normalizedTerm)) {
                return true;
            }
        }

        return false;
    }

    /** Return the canonical value persisted for an exact label exclusion. */
    public function exactFilterValue(string $summary): string
    {
        $normalized = $this->normalize($summary);

        return '' === $normalized ? self::EMPTY_SUMMARY_FILTER : $normalized;
    }

    /**
     * Build a stable comparison value while tolerating casing and whitespace variations.
     */
    public function normalize(string $summary): string
    {
        $summary = trim($summary);
        $summary = preg_replace('/\s+/u', ' ', $summary) ?? $summary;

        return mb_substr(
            mb_strtolower($summary),
            0,
            CalendarSyncImport::MAX_SUMMARY_FILTER_LENGTH,
        );
    }
}
