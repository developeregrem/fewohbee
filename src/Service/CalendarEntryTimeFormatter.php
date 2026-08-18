<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarEntry;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Renders a calendar entry's times as one readable label.
 *
 * Kept out of the entity and out of Twig so the year-overview popover, the
 * reminder list and any later consumer (mail and PDF templates) phrase it
 * identically. Times go through \IntlDateFormatter rather than a hand-written
 * format string, so a locale that does not write 24-hour clock times gets its
 * own notation.
 */
final class CalendarEntryTimeFormatter
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * The label for an entry: "13:00", "13:00 - 14:00", or "- 14:00" on the
     * closing day of a multi-day entry, whose start sits on the first day.
     * Null for an all-day entry, which is what a null means everywhere else
     * too - callers render nothing at all in that case.
     */
    public function format(CalendarEntry $entry, ?string $locale = null): ?string
    {
        $locale ??= $this->translator->getLocale();
        $start = $entry->getTime();
        $end = $entry->getEndTime();

        return match (true) {
            null !== $start && null !== $end => $this->translator->trans('calendar_entry.time.range', [
                '%start%' => $this->formatTime($start, $locale),
                '%end%' => $this->formatTime($end, $locale),
            ], null, $locale),
            null !== $start => $this->formatTime($start, $locale),
            null !== $end => $this->translator->trans('calendar_entry.time.until', [
                '%end%' => $this->formatTime($end, $locale),
            ], null, $locale),
            default => null,
        };
    }

    /**
     * A TIME column hydrates into a DateTimeImmutable on an arbitrary date in
     * the PHP default zone, so the formatter is pinned to that same zone -
     * left on its own default it would shift the clock digits it is supposed
     * to be reproducing.
     */
    private function formatTime(\DateTimeImmutable $time, string $locale): string
    {
        $formatter = new \IntlDateFormatter(
            $locale,
            \IntlDateFormatter::NONE,
            \IntlDateFormatter::SHORT,
            $time->getTimezone(),
        );

        return (string) $formatter->format($time);
    }
}
