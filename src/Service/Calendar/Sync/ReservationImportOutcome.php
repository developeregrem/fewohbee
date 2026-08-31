<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sync;

/** Describes how one portal VEVENT was handled during reservation import. */
enum ReservationImportOutcome
{
    case Synchronized;
    case MissingRequiredData;
    case Past;
    case ConflictSkipped;
}
