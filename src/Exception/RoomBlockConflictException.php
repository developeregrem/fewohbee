<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown when a room block cannot be created/updated because the period
 * overlaps blocking reservations or other blocks.
 */
class RoomBlockConflictException extends \RuntimeException
{
    /**
     * @param array<int, array{room: \App\Entity\Appartment, reservations: \App\Entity\Reservation[], blocks: \App\Entity\RoomBlock[]}> $conflicts keyed by room id
     */
    public function __construct(private readonly array $conflicts)
    {
        parent::__construct('roomblock.conflict.heading');
    }

    /**
     * @return array<int, array{room: \App\Entity\Appartment, reservations: \App\Entity\Reservation[], blocks: \App\Entity\RoomBlock[]}>
     */
    public function getConflicts(): array
    {
        return $this->conflicts;
    }
}
