<?php

declare(strict_types=1);

namespace App\Service\Ics;

/**
 * Minimal ICS (RFC 5545) reader shared by every feature that consumes an
 * external calendar feed (channel-manager reservation sync, configurable
 * calendars). Deliberately doesn't expand RRULE-recurring events - the
 * source feed is expected to list one VEVENT per occurrence.
 */
class IcsEventParser
{
    public function isValidCalendar(string $content): bool
    {
        return str_contains($content, 'BEGIN:VCALENDAR') && str_contains($content, 'END:VCALENDAR');
    }

    /**
     * @return array<int, array<string, string>> flat property => value maps, one per VEVENT
     */
    public function parseEvents(string $content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $unfolded = [];
        foreach ($lines as $line) {
            if ('' === $line) {
                continue;
            }
            if ([] !== $unfolded && (str_starts_with($line, ' ') || str_starts_with($line, "\t"))) {
                $unfolded[count($unfolded) - 1] .= ltrim($line);
            } else {
                $unfolded[] = $line;
            }
        }

        $events = [];
        $current = null;
        foreach ($unfolded as $line) {
            if ('BEGIN:VEVENT' === $line) {
                $current = [];
                continue;
            }
            if ('END:VEVENT' === $line) {
                if (is_array($current)) {
                    $events[] = $current;
                }
                $current = null;
                continue;
            }
            if (!is_array($current)) {
                continue;
            }

            $parts = explode(':', $line, 2);
            if (2 !== count($parts)) {
                continue;
            }
            [$name, $value] = $parts;
            $name = strtoupper(explode(';', $name, 2)[0]);
            $current[$name] = $value;
        }

        return $events;
    }

    /** Parses an iCal date (Ymd) or date-time value into a DateTimeImmutable. */
    public function parseDate(string $value): ?\DateTimeImmutable
    {
        if (1 === preg_match('/^\d{8}$/', $value)) {
            $date = \DateTimeImmutable::createFromFormat('Ymd', $value);

            return $date ?: null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $exception) {
            return null;
        }
    }
}
