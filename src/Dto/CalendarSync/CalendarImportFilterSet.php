<?php

declare(strict_types=1);

namespace App\Dto\CalendarSync;

use App\Entity\CalendarSyncImport;

/**
 * Carries the exact and partial portal-label exclusions shared between room imports.
 */
final readonly class CalendarImportFilterSet
{
    /**
     * @param list<string> $exact
     * @param list<string> $terms
     */
    public function __construct(
        public array $exact,
        public array $terms,
    ) {
    }

    public static function fromImport(CalendarSyncImport $import): self
    {
        return new self(
            exact: $import->getExcludedSummaries(),
            terms: $import->getExcludedSummaryTerms(),
        );
    }

    /**
     * @return array{exact: list<string>, terms: list<string>}
     */
    public function toArray(): array
    {
        return [
            'exact' => $this->exact,
            'terms' => $this->terms,
        ];
    }
}
