<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Dto\Ics\IcsOccurrence;
use App\Service\CalendarEntryTimeRules;
use App\Service\Ics\IcsEventSpanResolver;
use App\Service\Ics\IcsOccurrenceReader;
use PHPUnit\Framework\TestCase;

final class IcsEventSpanResolverTest extends TestCase
{
    private IcsEventSpanResolver $resolver;
    private IcsOccurrenceReader $reader;
    private \DateTimeZone $berlin;

    protected function setUp(): void
    {
        $this->resolver = new IcsEventSpanResolver(new CalendarEntryTimeRules());
        $this->reader = new IcsOccurrenceReader();
        $this->berlin = new \DateTimeZone('Europe/Berlin');
    }

    public function testUtcValueIsConvertedIntoTheDisplayZone(): void
    {
        // 11:00Z is 13:00 in Berlin in August - the offset that made a Google
        // feed's 13:00 appointment show up as 11:00.
        $span = $this->resolve(['DTSTART' => '20260814T110000Z', 'DTEND' => '20260814T120000Z']);

        self::assertNotNull($span);
        self::assertSame('13:00', $span->startTime?->format('H:i'));
        self::assertSame('14:00', $span->endTime?->format('H:i'));
    }

    public function testTzidParameterNamesTheZoneTheValueIsIn(): void
    {
        $span = $this->resolve([
            'DTSTART' => '20260814T130000',
            'DTSTART;PARAMS' => 'TZID=America/New_York',
        ]);

        // 13:00 in New York is 19:00 in Berlin.
        self::assertNotNull($span);
        self::assertSame('19:00', $span->startTime?->format('H:i'));
    }

    public function testAValueWithoutZoneInformationIsReadAsLocalTime(): void
    {
        $span = $this->resolve(['DTSTART' => '20260814T130000']);

        self::assertNotNull($span);
        self::assertSame('13:00', $span->startTime?->format('H:i'));
    }

    public function testTheZoneDecidesTheCalendarDay(): void
    {
        // 22:30Z on the 14th is 00:30 on the 15th in Berlin.
        $span = $this->resolve(['DTSTART' => '20260814T223000Z']);

        self::assertNotNull($span);
        self::assertSame(['2026-08-15'], $this->dateKeys($span->dates));
    }

    public function testAllDayEventCarriesNoTimesAtAll(): void
    {
        $span = $this->resolve(['DTSTART' => '20260814', 'DTEND' => '20260815']);

        self::assertNotNull($span);
        self::assertSame(['2026-08-14'], $this->dateKeys($span->dates));
        self::assertNull($span->startTime);
        self::assertNull($span->endTime);
    }

    public function testAllDayDtEndIsExclusive(): void
    {
        $span = $this->resolve(['DTSTART' => '20260801', 'DTEND' => '20260804']);

        self::assertNotNull($span);
        self::assertSame(['2026-08-01', '2026-08-02', '2026-08-03'], $this->dateKeys($span->dates));
    }

    public function testTimedMultiDayEventKeepsItsClosingDay(): void
    {
        $span = $this->resolve(['DTSTART' => '20260814T130000', 'DTEND' => '20260816T140000']);

        self::assertNotNull($span);
        self::assertSame(['2026-08-14', '2026-08-15', '2026-08-16'], $this->dateKeys($span->dates));
        self::assertSame('13:00', $span->startTime?->format('H:i'));
        self::assertSame('14:00', $span->endTime?->format('H:i'));
        self::assertSame('2026-08-16', $span->lastDate()->format('Y-m-d'));
    }

    public function testEventEndingAtMidnightCoversOnlyTheDayBefore(): void
    {
        $span = $this->resolve(['DTSTART' => '20260814T180000', 'DTEND' => '20260815T000000']);

        self::assertNotNull($span);
        self::assertSame(['2026-08-14'], $this->dateKeys($span->dates));
        // Kept as 00:00, which CalendarEntryTimeRules reads as the end of the
        // day - the case Alex's review flagged as unsavable in the form.
        self::assertSame('18:00', $span->startTime?->format('H:i'));
        self::assertSame('00:00', $span->endTime?->format('H:i'));
    }

    public function testMultiDayEventEndingAtMidnightLeavesItsLastDayAllDay(): void
    {
        // Aug 15 runs from its own midnight to the next, so it has no end time
        // worth stating - a lone "- 00:00" would read as ending at the day's
        // beginning.
        $span = $this->resolve(['DTSTART' => '20260814T180000', 'DTEND' => '20260816T000000']);

        self::assertNotNull($span);
        self::assertSame(['2026-08-14', '2026-08-15'], $this->dateKeys($span->dates));
        self::assertNull($span->endTime);
    }

    public function testSecondsAreDroppedFromImportedTimes(): void
    {
        $span = $this->resolve(['DTSTART' => '20260814T130045', 'DTEND' => '20260814T144030']);

        self::assertNotNull($span);
        self::assertSame('13:00:00', $span->startTime?->format('H:i:s'));
        self::assertSame('14:40:00', $span->endTime?->format('H:i:s'));
    }

    /**
     * Without normalising, 00:00:30 is midnight to CalendarEntryTimeRules but
     * not to the day loop, which would cover a further calendar day for those
     * thirty seconds.
     */
    public function testAnEndJustAfterMidnightDoesNotClaimAnExtraDay(): void
    {
        $span = $this->resolve(['DTSTART' => '20260814T180000', 'DTEND' => '20260815T000030']);

        self::assertNotNull($span);
        self::assertSame(['2026-08-14'], $this->dateKeys($span->dates));
        self::assertSame('00:00', $span->endTime?->format('H:i'));
    }

    public function testAnEventShorterThanAMinuteIsDiscarded(): void
    {
        // Both ends normalise to 13:00, leaving a zero-length period - handled
        // like any other, rather than slipping through on seconds the rest of
        // the application cannot represent.
        self::assertNull($this->resolve(['DTSTART' => '20260814T130010', 'DTEND' => '20260814T130045']));
    }

    public function testEventWithoutDtEndIsASingleDay(): void
    {
        $span = $this->resolve(['DTSTART' => '20260814T130000']);

        self::assertNotNull($span);
        self::assertSame(['2026-08-14'], $this->dateKeys($span->dates));
        self::assertNull($span->endTime);
    }

    public function testDtEndBeforeDtStartIsDiscarded(): void
    {
        self::assertNull($this->resolve(['DTSTART' => '20260814T130000', 'DTEND' => '20260814T120000']));
    }

    public function testDtEndEqualToDtStartIsDiscarded(): void
    {
        self::assertNull($this->resolve(['DTSTART' => '20260814T130000', 'DTEND' => '20260814T130000']));
        self::assertNull($this->resolve(['DTSTART' => '20260814', 'DTEND' => '20260814']));
    }

    public function testUnparseableDtEndIsDiscardedRatherThanTreatedAsAbsent(): void
    {
        self::assertNull($this->resolve(['DTSTART' => '20260814T130000', 'DTEND' => 'not-a-date']));
    }

    public function testMissingDtStartIsDiscarded(): void
    {
        self::assertNull($this->resolve(['DTEND' => '20260814T130000']));
    }

    public function testAnAbsurdlyLongSpanIsDiscarded(): void
    {
        self::assertNull($this->resolve(['DTSTART' => '20260101', 'DTEND' => '20300101']));
    }

    /** @param array<string, string> $event */
    /**
     * Wraps the properties into a one-event feed and takes it through the real
     * reader, so these cases cover both halves of the import: reading a
     * property into an instant, and turning that into the days it covers.
     *
     * @param array<string, string> $event
     */
    private function resolve(array $event): ?\App\Dto\Ics\IcsEventSpan
    {
        $occurrence = $this->read($event);

        return null === $occurrence ? null : $this->resolver->resolve($occurrence);
    }

    /**
     * @param array<string, string> $event
     */
    private function read(array $event): ?IcsOccurrence
    {
        $properties = '';
        foreach (['DTSTART', 'DTEND'] as $name) {
            if (!isset($event[$name])) {
                continue;
            }

            $value = $event[$name];
            $params = $event[$name.';PARAMS'] ?? null;
            // A bare eight-digit value is a date without a time, which RFC 5545
            // only treats as such when VALUE=DATE says so.
            if (null === $params && 8 === \strlen($value) && ctype_digit($value)) {
                $params = 'VALUE=DATE';
            }

            $properties .= $name.(null !== $params ? ';'.$params : '').':'.$value."\r\n";
        }

        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//test//EN\r\n"
            ."BEGIN:VEVENT\r\nUID:test\r\nSUMMARY:Test\r\n".$properties."END:VEVENT\r\nEND:VCALENDAR\r\n";

        // Deliberately wide, so a case is never discarded for sitting outside
        // the window when what it means to check is the span arithmetic.
        try {
            $result = $this->reader->read(
                $ics,
                $this->berlin,
                new \DateTimeImmutable('2000-01-01', $this->berlin),
                new \DateTimeImmutable('2100-01-01', $this->berlin),
            );
        } catch (\Throwable) {
            return null;
        }

        return $result->occurrences[0] ?? null;
    }

    /**
     * @param list<\DateTimeImmutable> $dates
     *
     * @return list<string>
     */
    private function dateKeys(array $dates): array
    {
        return array_map(static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d'), $dates);
    }
}
