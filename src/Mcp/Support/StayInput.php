<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use App\Entity\Appartment;
use App\Entity\ReservationOrigin;
use App\Mcp\Security\McpToolException;
use App\Service\Api\StayParameterResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Validated stay parameters shared by the price quote and booking tools.
 */
final class StayInput
{
    public const MAX_NIGHTS = 366;

    /**
     * @param array<int, int> $guestCounts
     */
    private function __construct(
        public readonly Appartment $apartment,
        public readonly \DateTimeImmutable $arrival,
        public readonly \DateTimeImmutable $departure,
        public readonly int $persons,
        public readonly array $guestCounts,
        public readonly ReservationOrigin $origin,
    ) {
    }

    /**
     * @param array<int|string, mixed> $guestCounts guest category id => head count
     */
    public static function resolve(
        EntityManagerInterface $em,
        StayParameterResolver $resolver,
        int $apartmentId,
        string $arrival,
        string $departure,
        ?int $persons,
        array $guestCounts,
        ?int $originId,
    ): self {
        $apartment = $em->getRepository(Appartment::class)->find($apartmentId);
        if (!$apartment instanceof Appartment) {
            throw McpToolException::invalid('Unknown apartment id.');
        }

        $start = McpInput::date($arrival, 'arrival');
        $end = McpInput::date($departure, 'departure');
        if ($end <= $start) {
            throw McpToolException::invalid("'departure' must be after 'arrival'.");
        }
        if ((int) $start->diff($end)->days > self::MAX_NIGHTS) {
            throw McpToolException::invalid(\sprintf('A stay must not exceed %d nights.', self::MAX_NIGHTS));
        }
        if (null !== $persons && $persons < 1) {
            throw McpToolException::invalid("'persons' must be at least 1.");
        }

        $counts = McpInput::guard(static fn (): array => $resolver->resolveGuestCounts($guestCounts));
        $resolvedPersons = McpInput::guard(static fn (): int => $resolver->resolvePersons($persons, $counts));
        $origin = McpInput::guard(static fn (): ReservationOrigin => $resolver->resolveOrigin($originId));

        return new self($apartment, $start, $end, $resolvedPersons, $counts, $origin);
    }

    public function nights(): int
    {
        return (int) $this->arrival->diff($this->departure)->days;
    }
}
