<?php

namespace App\Entity\Enum;

/**
 * Identifies where a room block originated from.
 */
enum RoomBlockSource: string
{
    case MANUAL = 'manual';
}
