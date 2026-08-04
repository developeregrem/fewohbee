<?php

declare(strict_types=1);

namespace App\Service\Api;

use App\Entity\Reservation;

/**
 * Classifies a reservation relative to a date range as arrival/departure/inhouse.
 *
 * Closed-form generalization of the per-day rules in FrontdeskViewService::buildItems():
 * for a single-day range (start === end) both yield identical results.
 */
class ReservationTypeClassifier
{
    public const TYPES = ['arrival', 'departure', 'inhouse'];

    /**
     * @return list<'arrival'|'departure'|'inhouse'>
     */
    public function classify(Reservation $reservation, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $rangeStart = $start->format('Y-m-d');
        $rangeEnd = $end->format('Y-m-d');
        $resStart = $reservation->getStartDate()->format('Y-m-d');
        $resEnd = $reservation->getEndDate()->format('Y-m-d');

        $types = [];
        if ($resStart >= $rangeStart && $resStart <= $rangeEnd) {
            $types[] = 'arrival';
        }
        if ($resEnd >= $rangeStart && $resEnd <= $rangeEnd) {
            $types[] = 'departure';
        }

        // In-house: at least one day in the range lies strictly between arrival and departure day,
        // i.e. [resStart+1, resEnd-1] intersects [rangeStart, rangeEnd].
        $interiorStart = \DateTimeImmutable::createFromInterface($reservation->getStartDate())->modify('+1 day')->format('Y-m-d');
        $interiorEnd = \DateTimeImmutable::createFromInterface($reservation->getEndDate())->modify('-1 day')->format('Y-m-d');
        if (max($interiorStart, $rangeStart) <= min($interiorEnd, $rangeEnd)) {
            $types[] = 'inhouse';
        }

        return $types;
    }
}
