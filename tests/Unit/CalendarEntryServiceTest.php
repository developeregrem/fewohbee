<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Calendar;
use App\Entity\CalendarEntry;
use App\Service\CalendarEntryService;
use App\Service\CalendarEntryTimeRules;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class CalendarEntryServiceTest extends TestCase
{
    public function testAllDaySingleEntryPassesValidation(): void
    {
        $entry = $this->entry('2026-08-14');

        self::assertSame([], $this->service()->validateSingle($entry));
    }

    public function testEndBeforeStartIsReportedOnTheEndTimeField(): void
    {
        $entry = $this->entry('2026-08-14', '13:00', '12:00');

        $violations = $this->service()->validateSingle($entry);
        self::assertCount(1, $violations);
        self::assertSame('endTime', $violations[0]->field);
        self::assertSame('calendar_entry.form.end_time_before_start', $violations[0]->messageKey);
    }

    /**
     * The rule the ICS import relies on: an entry it writes for an event
     * running until midnight must survive a round trip through the edit form.
     */
    public function testEndAtMidnightIsAcceptedAlongsideAStartTime(): void
    {
        $entry = $this->entry('2026-08-14', '18:00', '00:00');

        self::assertSame([], $this->service()->validateSingle($entry));
    }

    public function testEndAtMidnightWithoutAStartTimeGetsItsOwnMessage(): void
    {
        $entry = $this->entry('2026-08-14', null, '00:00');

        $violations = $this->service()->validateSingle($entry);
        self::assertCount(1, $violations);
        self::assertSame('calendar_entry.form.end_time_midnight_without_start', $violations[0]->messageKey);
    }

    public function testTimeOrderIsNotCheckedAcrossAPeriod(): void
    {
        // 18:00 on the first day, 09:00 on the last - legitimate, because the
        // two times sit on different days.
        $entry = $this->entry('2026-08-14', '18:00', '09:00');

        self::assertSame([], $this->service()->validateRange($entry, new \DateTimeImmutable('2026-08-16')));
    }

    public function testTimeOrderIsCheckedWhenThePeriodIsASingleDay(): void
    {
        $entry = $this->entry('2026-08-14', '18:00', '09:00');

        $violations = $this->service()->validateRange($entry, new \DateTimeImmutable('2026-08-14'));
        self::assertCount(1, $violations);
        self::assertSame('endTime', $violations[0]->field);
    }

    public function testAnOverlongPeriodIsReportedOnTheEndDateField(): void
    {
        $entry = $this->entry('2026-08-14');

        $violations = $this->service()->validateRange($entry, new \DateTimeImmutable('2030-08-14'));
        self::assertCount(1, $violations);
        self::assertSame('dateTo', $violations[0]->field);
        self::assertSame('calendar_entry.form.date_to_too_far', $violations[0]->messageKey);
        self::assertSame(['%max%' => '366'], $violations[0]->parameters);
    }

    public function testASingleDayCreatesOneEntryKeepingBothTimes(): void
    {
        $entry = $this->entry('2026-08-14', '13:00', '14:00');
        $persisted = [];

        $created = $this->service($persisted)->createRange($entry, null);

        self::assertSame(1, $created);
        self::assertCount(1, $persisted);
        self::assertSame('13:00', $persisted[0]->getTime()?->format('H:i'));
        self::assertSame('14:00', $persisted[0]->getEndTime()?->format('H:i'));
    }

    /**
     * The split that mirrors a multi-day ICS event: start time on the first
     * day, end time on the last, the days between running all day.
     */
    public function testAPeriodPutsTheStartTimeFirstAndTheEndTimeLast(): void
    {
        $entry = $this->entry('2026-08-14', '13:00', '14:00');
        $persisted = [];

        $created = $this->service($persisted)->createRange($entry, new \DateTimeImmutable('2026-08-16'));

        self::assertSame(3, $created);
        self::assertCount(3, $persisted);

        self::assertSame('13:00', $persisted[0]->getTime()?->format('H:i'));
        self::assertNull($persisted[0]->getEndTime());

        self::assertNull($persisted[1]->getTime());
        self::assertNull($persisted[1]->getEndTime());

        self::assertNull($persisted[2]->getTime());
        self::assertSame('14:00', $persisted[2]->getEndTime()?->format('H:i'));
    }

    /**
     * A period ending at midnight used to store a lone "- 00:00" on its
     * closing day - an entry the edit form then refused to save. The closing
     * day runs to its own end anyway, which is what all-day already means.
     */
    public function testAPeriodEndingAtMidnightLeavesItsClosingDayAllDay(): void
    {
        $entry = $this->entry('2026-08-14', '18:00', '00:00');
        $persisted = [];

        $this->service($persisted)->createRange($entry, new \DateTimeImmutable('2026-08-16'));

        self::assertCount(3, $persisted);
        self::assertSame('18:00', $persisted[0]->getTime()?->format('H:i'));
        self::assertNull($persisted[2]->getTime());
        self::assertNull($persisted[2]->getEndTime());
    }

    /** Everything createRange() writes must survive a round trip through the edit form. */
    public function testEveryDayOfAMidnightPeriodValidatesOnItsOwn(): void
    {
        $entry = $this->entry('2026-08-14', '18:00', '00:00');
        $persisted = [];

        $service = $this->service($persisted);
        $service->createRange($entry, new \DateTimeImmutable('2026-08-16'));

        foreach ($persisted as $day) {
            self::assertSame([], $service->validateSingle($day), 'a created entry fails its own validation');
        }
    }

    public function testASingleDayKeepsAMidnightEndTime(): void
    {
        $entry = $this->entry('2026-08-14', '18:00', '00:00');
        $persisted = [];

        $this->service($persisted)->createRange($entry, null);

        self::assertCount(1, $persisted);
        self::assertSame('00:00', $persisted[0]->getEndTime()?->format('H:i'));
    }

    public function testAnEndDateNotAfterTheStartMeansASingleDay(): void
    {
        $entry = $this->entry('2026-08-14');
        $persisted = [];

        self::assertSame(1, $this->service($persisted)->createRange($entry, new \DateTimeImmutable('2026-08-10')));
    }

    /**
     * @param list<CalendarEntry> $persisted collects everything handed to persist()
     */
    private function service(array &$persisted = []): CalendarEntryService
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        return new CalendarEntryService($em, new CalendarEntryTimeRules());
    }

    private function entry(string $date, ?string $time = null, ?string $endTime = null): CalendarEntry
    {
        $entry = (new CalendarEntry())
            ->setCalendar(new Calendar())
            ->setDate(new \DateTimeImmutable($date))
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
