<?php

declare(strict_types=1);

namespace App\Dto\Api;

use App\Entity\Subsidiary;

/**
 * API representation of a branch and its master data.
 *
 * The invoice number pattern is deliberately not exposed: it is internal billing
 * configuration, of no use to an API consumer and not something a read token should
 * hand out.
 */
final readonly class SubsidiaryDto
{
    /**
     * @param array<int, list<array{from: string, to: string}>>|null $openingHours weekday
     *        (1 = Monday … 7 = Sunday) to its time ranges, or null when no opening hours
     *        are configured at all; within a configured set, an absent weekday is closed
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $description,
        public ?array $openingHours,
        public ?string $openingHoursNote,
    ) {
    }

    public static function fromEntity(Subsidiary $subsidiary): self
    {
        $openingHours = [];
        foreach ($subsidiary->getOpeningHours() as $weekday => $ranges) {
            // Named keys rather than the entity's positional [from, to] pairs: a consumer
            // reads range.from, not range[0]. Weekday keys start at 1, so json_encode
            // always renders the map as an object and never as a list.
            $openingHours[$weekday] = array_map(
                static fn (array $range): array => ['from' => $range[0], 'to' => $range[1]],
                $ranges
            );
        }

        return new self(
            (int) $subsidiary->getId(),
            (string) $subsidiary->getName(),
            (string) $subsidiary->getDescription(),
            // Null rather than an empty array: an empty PHP array serialises to [], which
            // would contradict the object the schema promises. Null says "not configured"
            // without a second shape for the same field.
            [] === $openingHours ? null : $openingHours,
            $subsidiary->getOpeningHoursNote(),
        );
    }
}
