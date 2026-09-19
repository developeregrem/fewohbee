<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Calendar\Sync\Ics\IcsOccurrenceReader;
use PHPUnit\Framework\TestCase;

/** Verify the distinct recurrence contracts for portal and custom calendars. */
final class IcsOccurrenceReaderTest extends TestCase
{
    public function testRawEventModeIgnoresRecurrenceRulesAndDates(): void
    {
        $content = <<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:portal-booking
DTSTART;VALUE=DATE:20300101
DTEND;VALUE=DATE:20300102
DESCRIPTION:Portal reservation
RRULE:FREQ=DAILY;COUNT=3
RDATE;VALUE=DATE:20300110
END:VEVENT
END:VCALENDAR
ICS;

        $result = (new IcsOccurrenceReader())->readEvents(
            $content,
            new \DateTimeZone('Europe/Berlin'),
        );

        self::assertSame(1, $result->sourceEventCount);
        self::assertSame(0, $result->skipped);
        self::assertCount(1, $result->occurrences);
        self::assertSame('portal-booking', $result->occurrences[0]->uid);
        self::assertSame('Portal reservation', $result->occurrences[0]->description);
        self::assertSame('2030-01-01', $result->occurrences[0]->start->format('Y-m-d'));
        self::assertSame('2030-01-02', $result->occurrences[0]->end?->format('Y-m-d'));
    }

    public function testExpandedModeStillMaterializesCustomCalendarRecurrences(): void
    {
        $content = <<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:custom-series
SUMMARY:Recurring task
DTSTART;VALUE=DATE:20300101
DTEND;VALUE=DATE:20300102
RRULE:FREQ=DAILY;COUNT=3
END:VEVENT
END:VCALENDAR
ICS;

        $result = (new IcsOccurrenceReader())->read(
            $content,
            new \DateTimeZone('Europe/Berlin'),
            new \DateTimeImmutable('2030-01-01', new \DateTimeZone('Europe/Berlin')),
            new \DateTimeImmutable('2030-02-01', new \DateTimeZone('Europe/Berlin')),
        );

        self::assertCount(3, $result->occurrences);
    }
}
