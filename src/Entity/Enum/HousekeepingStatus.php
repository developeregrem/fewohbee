<?php

namespace App\Entity\Enum;

/**
 * Describes the lifecycle state of a housekeeping task for a room-day.
 */
enum HousekeepingStatus: string
{
    case OPEN = 'OPEN';
    case IN_PROGRESS = 'IN_PROGRESS';
    case CLEANED = 'CLEANED';
    case INSPECTED = 'INSPECTED';
}
