<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\CalendarEntry;
use App\Service\CalendarEntryTimeFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Exposes CalendarEntryTimeFormatter to templates that render entities
 * directly (the reminder list). Views built around a DTO carry the formatted
 * label in the DTO instead - see DayCalendarEntry::$time.
 */
final class CalendarEntryTimeExtension extends AbstractExtension
{
    public function __construct(private readonly CalendarEntryTimeFormatter $formatter)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('calendar_entry_time', $this->format(...)),
        ];
    }

    public function format(CalendarEntry $entry): ?string
    {
        return $this->formatter->format($entry);
    }
}
