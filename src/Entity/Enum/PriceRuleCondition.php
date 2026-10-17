<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * When a price rule applies to a night. Every rule may additionally be limited to weekdays and
 * a date range, so "always" covers weekends, seasons and events.
 */
enum PriceRuleCondition: string
{
    case ALWAYS = 'always';

    /** The night is at most N days away. */
    case LAST_MINUTE = 'last_minute';

    /** The night is at least N days away. */
    case EARLY_BIRD = 'early_bird';

    /** At least X % of the rooms are booked on that night. */
    case OCCUPANCY_HIGH = 'occupancy_high';

    /** At most X % of the rooms are booked on a night that is at most N days away. */
    case OCCUPANCY_LOW = 'occupancy_low';

    public function usesDays(): bool
    {
        return self::LAST_MINUTE === $this || self::EARLY_BIRD === $this || self::OCCUPANCY_LOW === $this;
    }

    public function usesOccupancy(): bool
    {
        return self::OCCUPANCY_HIGH === $this || self::OCCUPANCY_LOW === $this;
    }
}
