<?php

declare(strict_types=1);

namespace App\Dto\ReservationTable;

use App\Entity\Reservation;

final class TableCell
{
    public const TYPE_EMPTY = 'empty';
    public const TYPE_RESERVATION = 'reservation';
    public const TYPE_BLOCKED = 'blocked';

    public const POS_FULL = 'full';
    public const POS_START = 'start';
    public const POS_END = 'end';
    public const POS_MIDDLE = 'middle';
    public const POS_SINGLE = 'single';

    public const SIDE_LEFT = 'left';
    public const SIDE_RIGHT = 'right';

    public function __construct(
        public readonly string $date,
        public readonly string $type = self::TYPE_EMPTY,
        public readonly int $span = 1,
        public readonly string $position = self::POS_FULL,
        public readonly ?string $side = null,
        public readonly ?Reservation $reservation = null,
        public readonly ?string $displayName = null,
        public readonly ?string $color = null,
        public readonly ?string $contrastColor = null,
        public readonly ?int $reservationId = null,
        public readonly bool $startsAtDayBoundary = false,
    ) {
    }
}
