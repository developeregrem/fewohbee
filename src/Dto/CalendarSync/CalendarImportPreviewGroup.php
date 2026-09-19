<?php

declare(strict_types=1);

namespace App\Dto\CalendarSync;

/**
 * Groups current portal events that share the same user-visible calendar label.
 */
final readonly class CalendarImportPreviewGroup
{
    public function __construct(
        public string $summary,
        public string $filterValue,
        public int $count,
        public \DateTimeImmutable $exampleStart,
        public ?\DateTimeImmutable $exampleEnd,
    ) {
    }

    /**
     * @return array{summary: string, filterValue: string, count: int, exampleStart: string, exampleEnd: string|null}
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'filterValue' => $this->filterValue,
            'count' => $this->count,
            'exampleStart' => $this->exampleStart->format('Y-m-d'),
            'exampleEnd' => $this->exampleEnd?->format('Y-m-d'),
        ];
    }
}
