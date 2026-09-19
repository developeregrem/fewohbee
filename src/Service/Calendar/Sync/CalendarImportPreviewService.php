<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sync;

use App\Dto\CalendarSync\CalendarImportPreview;
use App\Dto\CalendarSync\CalendarImportPreviewGroup;
use App\Dto\Ics\IcsOccurrence;
use App\Exception\CalendarSyncException;
use App\Exception\IcsFeedException;
use App\Exception\IcsFeedFailure;
use App\Service\Calendar\Sync\Ics\IcsFeedClient;
use App\Service\Calendar\Sync\Ics\IcsOccurrenceReader;

/**
 * Downloads a portal calendar and groups its current labels for the import setup preview.
 */
final class CalendarImportPreviewService
{
    public function __construct(
        private readonly IcsFeedClient $feedClient,
        private readonly IcsOccurrenceReader $occurrenceReader,
        private readonly CalendarImportSummaryMatcher $summaryMatcher,
    ) {
    }

    /**
     * Read and group current events without persisting or importing anything.
     *
     * @throws CalendarSyncException when the remote calendar cannot be read
     */
    public function preview(string $url, \DateTimeZone $zone): CalendarImportPreview
    {
        try {
            $content = $this->feedClient->fetch($url);
        } catch (IcsFeedException $exception) {
            $translationKey = match ($exception->failure) {
                IcsFeedFailure::HttpStatus => 'calendar.sync.import.error.http_status',
                IcsFeedFailure::Unreachable => 'calendar.sync.import.error.unreachable',
                IcsFeedFailure::TooLarge => 'calendar.sync.import.error.too_large',
            };

            throw new CalendarSyncException($translationKey, previous: $exception);
        }

        if (!$this->occurrenceReader->isValidCalendar($content)) {
            throw new CalendarSyncException('calendar.sync.import.error.invalid_ical');
        }

        try {
            $read = $this->occurrenceReader->readEvents($content, $zone);
        } catch (\Throwable $exception) {
            throw new CalendarSyncException('calendar.sync.import.error.invalid_ical', previous: $exception);
        }

        $today = new \DateTimeImmutable('today', $zone);
        /** @var array<string, array{summary: string, filterValue: string, count: int, start: \DateTimeImmutable, end: ?\DateTimeImmutable}> $groups */
        $groups = [];
        $eventCount = 0;

        foreach ($read->occurrences as $occurrence) {
            $end = $occurrence->end ?? $occurrence->start;
            if ($end < $today) {
                continue;
            }

            ++$eventCount;
            $filterValue = $this->summaryMatcher->exactFilterValue($occurrence->summary);
            $key = 'summary:'.$filterValue;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'summary' => $this->displaySummary($occurrence),
                    'filterValue' => $filterValue,
                    'count' => 0,
                    'start' => $occurrence->start,
                    'end' => $occurrence->end,
                ];
            }
            ++$groups[$key]['count'];
        }

        $previewGroups = array_map(
            static fn (array $group): CalendarImportPreviewGroup => new CalendarImportPreviewGroup(
                summary: $group['summary'],
                filterValue: $group['filterValue'],
                count: $group['count'],
                exampleStart: $group['start'],
                exampleEnd: $group['end'],
            ),
            array_values($groups),
        );
        usort($previewGroups, static function (CalendarImportPreviewGroup $left, CalendarImportPreviewGroup $right): int {
            return $right->count <=> $left->count ?: strnatcasecmp($left->summary, $right->summary);
        });

        return new CalendarImportPreview($previewGroups, $eventCount, $read->skipped);
    }

    /** Keep maliciously long labels out of the preview while preserving the actual filter value. */
    private function displaySummary(IcsOccurrence $occurrence): string
    {
        $summary = trim($occurrence->summary);
        $summary = preg_replace('/\s+/u', ' ', $summary) ?? $summary;

        return mb_substr($summary, 0, 255);
    }
}
