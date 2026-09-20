<?php

declare(strict_types=1);

namespace App\Dto\CalendarSync;

/**
 * Carries the grouped, current entries shown before a portal-calendar import is saved.
 */
final readonly class CalendarImportPreview
{
    /**
     * @param list<CalendarImportPreviewGroup> $groups
     */
    public function __construct(
        public array $groups,
        public int $eventCount,
        public int $skippedCount,
    ) {
    }

    /**
     * @return array{groups: list<array{summary: string, filterValue: string, count: int, exampleStart: string, exampleEnd: string|null}>, eventCount: int, skippedCount: int}
     */
    public function toArray(): array
    {
        return [
            'groups' => array_map(
                static fn (CalendarImportPreviewGroup $group): array => $group->toArray(),
                $this->groups,
            ),
            'eventCount' => $this->eventCount,
            'skippedCount' => $this->skippedCount,
        ];
    }
}
