<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Dto\Ics\IcsOccurrence;
use App\Service\Calendar\Sync\Ics\IcsOccurrenceReader;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * Sabre derives the end as start plus a duration in seconds, which is one
     * hour off once an all-day span crosses a daylight saving switch.
     *
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function allDaySpansAcrossDst(): iterable
    {
        yield 'autumn, DTEND' => ['20261004', 'DTEND;VALUE=DATE:20261101', '2026-10-04T00:00:00+02:00', '2026-11-01T00:00:00+01:00'];
        yield 'spring, DTEND' => ['20270320', 'DTEND;VALUE=DATE:20270401', '2027-03-20T00:00:00+01:00', '2027-04-01T00:00:00+02:00'];
        yield 'autumn, DURATION' => ['20261024', 'DURATION:P3D', '2026-10-24T00:00:00+02:00', '2026-10-27T00:00:00+01:00'];
        yield 'spring, DURATION' => ['20270327', 'DURATION:P3D', '2027-03-27T00:00:00+01:00', '2027-03-30T00:00:00+02:00'];
    }

    #[DataProvider('allDaySpansAcrossDst')]
    public function testRawEventModeEndsAllDaySpansAtMidnightAcrossDst(
        string $start,
        string $endLine,
        string $expectedStart,
        string $expectedEnd,
    ): void {
        $content = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:portal-booking
DTSTART;VALUE=DATE:{$start}
{$endLine}
END:VEVENT
END:VCALENDAR
ICS;

        $result = (new IcsOccurrenceReader())->readEvents(
            $content,
            new \DateTimeZone('Europe/Berlin'),
        );

        self::assertCount(1, $result->occurrences);
        self::assertTrue($result->occurrences[0]->allDay);
        self::assertSame($expectedStart, $result->occurrences[0]->start->format('c'));
        self::assertSame($expectedEnd, $result->occurrences[0]->end?->format('c'));
    }

    public function testExpandedModeEndsAllDayRecurrencesAtMidnightAcrossDst(): void
    {
        $content = <<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:weekend-series
DTSTART;VALUE=DATE:20270320
DTEND;VALUE=DATE:20270322
RRULE:FREQ=WEEKLY;COUNT=3
END:VEVENT
END:VCALENDAR
ICS;

        $berlin = new \DateTimeZone('Europe/Berlin');
        $result = (new IcsOccurrenceReader())->read(
            $content,
            $berlin,
            new \DateTimeImmutable('2027-03-01', $berlin),
            new \DateTimeImmutable('2027-05-01', $berlin),
        );

        self::assertSame(
            [
                '2027-03-20T00:00:00+01:00 2027-03-22T00:00:00+01:00',
                '2027-03-27T00:00:00+01:00 2027-03-29T00:00:00+02:00',
                '2027-04-03T00:00:00+02:00 2027-04-05T00:00:00+02:00',
            ],
            array_map(
                static fn (IcsOccurrence $o): string => $o->start->format('c').' '.$o->end?->format('c'),
                $result->occurrences,
            ),
        );
    }

    public function testTimedSpanAcrossDstKeepsItsElapsedDuration(): void
    {
        // A timed event is a fixed amount of elapsed time: twelve hours from
        // 21:00 on the night of the spring switch end at 10:00, not 09:00.
        $content = <<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:night-shift
DTSTART:20270327T200000Z
DTEND:20270328T080000Z
END:VEVENT
END:VCALENDAR
ICS;

        $result = (new IcsOccurrenceReader())->readEvents(
            $content,
            new \DateTimeZone('Europe/Berlin'),
        );

        self::assertFalse($result->occurrences[0]->allDay);
        self::assertSame('2027-03-27T21:00:00+01:00', $result->occurrences[0]->start->format('c'));
        self::assertSame('2027-03-28T10:00:00+02:00', $result->occurrences[0]->end?->format('c'));
    }
}
