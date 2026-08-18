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
     * Suffix under which a property's parameters are kept alongside its value,
     * e.g. DTSTART;TZID=Europe/Berlin:20260814T130000 yields both
     * `DTSTART` => '20260814T130000' and `DTSTART;PARAMS` => 'TZID=Europe/Berlin'.
     *
     * Stored under a separate key rather than changing the shape of the value
     * so consumers that only ever want the value (CalendarImportService) keep
     * reading it unchanged.
     */
    public const PARAMS_SUFFIX = ';PARAMS';

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
            $nameParts = explode(';', $name, 2);
            $name = strtoupper($nameParts[0]);
            $current[$name] = $value;
            if (isset($nameParts[1]) && '' !== $nameParts[1]) {
                $current[$name.self::PARAMS_SUFFIX] = $nameParts[1];
            }
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

    /** Whether a property value is a bare date (VALUE=DATE), i.e. an all-day one. */
    public function isDateOnly(string $value): bool
    {
        return 1 === preg_match('/^\d{8}$/', $value);
    }

    /**
     * Parses a date-time property into the instant it actually denotes, then
     * expresses it in $display.
     *
     * RFC 5545 knows three forms, and only the first was handled correctly
     * before: a trailing Z is UTC, a TZID parameter names the zone, and a bare
     * value is "floating" local time. Reading a UTC value and then showing its
     * UTC digits is what made a 13:00 event from a Google feed appear as 11:00.
     *
     * @param string $params the property's raw parameter string, if any
     */
    public function parseDateTimeInZone(string $value, ?string $params, \DateTimeZone $display): ?\DateTimeImmutable
    {
        if ($this->isDateOnly($value)) {
            $date = \DateTimeImmutable::createFromFormat('!Ymd', $value, $display);

            return $date ?: null;
        }

        // A trailing Z carries its own zone, so a TZID alongside it is ignored
        // (and would be invalid per RFC 5545 anyway).
        $zone = $display;
        if (!str_ends_with($value, 'Z')) {
            $tzid = $this->extractTzid($params);
            if (null !== $tzid) {
                $zone = $tzid;
            }
        }

        try {
            return (new \DateTimeImmutable($value, $zone))->setTimezone($display);
        } catch (\Exception $exception) {
            return null;
        }
    }

    /**
     * The zone named by a TZID parameter, or null when absent or unknown to
     * PHP - an unrecognised zone falls back to the caller's display zone
     * rather than dropping the event.
     */
    private function extractTzid(?string $params): ?\DateTimeZone
    {
        if (null === $params || 1 !== preg_match('/(?:^|;)TZID=([^;]+)/i', $params, $matches)) {
            return null;
        }

        try {
            return new \DateTimeZone(trim($matches[1], '"'));
        } catch (\Exception $exception) {
            return null;
        }
    }
}
