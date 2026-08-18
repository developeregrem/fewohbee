<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Calendar;
use App\Entity\CalendarEntry;
use App\Service\CalendarEntryTimeFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

final class CalendarEntryTimeFormatterTest extends TestCase
{
    public function testAllDayEntryHasNoLabel(): void
    {
        self::assertNull($this->formatter()->format($this->entry()));
    }

    public function testStartTimeAlone(): void
    {
        self::assertSame('13:00', $this->formatter()->format($this->entry('13:00')));
    }

    public function testStartAndEndTime(): void
    {
        self::assertSame('13:00 - 14:00', $this->formatter()->format($this->entry('13:00', '14:00')));
    }

    /** The closing day of a multi-day entry, whose start sits on the first day. */
    public function testEndTimeAlone(): void
    {
        self::assertSame('- 14:00', $this->formatter()->format($this->entry(null, '14:00')));
    }

    public function testMidnightEndIsShownAsSuch(): void
    {
        self::assertSame('18:00 - 00:00', $this->formatter()->format($this->entry('18:00', '00:00')));
    }

    /**
     * Times go through \IntlDateFormatter, so a locale that does not write
     * 24-hour clock times gets its own notation rather than German digits.
     */
    public function testTimesFollowTheLocale(): void
    {
        $label = $this->formatter('en_US')->format($this->entry('13:00', '14:00'), 'en_US');

        self::assertSame("1:00\u{202f}PM - 2:00\u{202f}PM", $label);
    }

    private function formatter(string $locale = 'de'): CalendarEntryTimeFormatter
    {
        $translator = new Translator($locale);
        $translator->addLoader('array', new ArrayLoader());
        foreach (['de', 'en_US'] as $each) {
            $translator->addResource('array', [
                'calendar_entry.time.range' => '%start% - %end%',
                'calendar_entry.time.until' => '- %end%',
            ], $each);
        }

        return new CalendarEntryTimeFormatter($translator);
    }

    private function entry(?string $time = null, ?string $endTime = null): CalendarEntry
    {
        $entry = (new CalendarEntry())
            ->setCalendar(new Calendar())
            ->setDate(new \DateTimeImmutable('2026-08-14'))
            ->setTitle('Restmüll');

        if (null !== $time) {
            $entry->setTime(new \DateTimeImmutable('1970-01-01 '.$time.':00'));
        }
        if (null !== $endTime) {
            $entry->setEndTime(new \DateTimeImmutable('1970-01-01 '.$endTime.':00'));
        }

        return $entry;
    }
}
