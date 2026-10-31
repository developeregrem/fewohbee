<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * What the rooms of a category are used for.
 *
 * Accommodation is booked per night and counts towards occupancy, overnight stays and the
 * tourist tax. An event space (seminar room, hall, outdoor area) is booked per day and stays
 * out of those figures; its turnover still counts. Internally a day is the same half-open
 * interval as a night, [day, day + 1), so availability and conflicts work unchanged.
 */
enum RoomCategoryKind: string
{
    case ACCOMMODATION = 'accommodation';
    case EVENT_SPACE = 'event_space';

    public function labelKey(): string
    {
        return 'category.kind.'.$this->value;
    }
}
