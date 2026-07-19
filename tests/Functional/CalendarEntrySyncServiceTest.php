<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Calendar;
use App\Entity\CalendarEntry;
use App\Repository\CalendarEntryRepository;
use App\Service\CalendarEntrySyncService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Verify that CalendarEntrySyncService expands multi-day (DTSTART..DTEND) VEVENTs into one CalendarEntry per day. */
final class CalendarEntrySyncServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CalendarEntrySyncService $service;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        $this->service = static::getContainer()->get(CalendarEntrySyncService::class);
    }

    public function testMultiDayEventExpandsIntoOneEntryPerDay(): void
    {
        $calendar = $this->createCalendar('Vacation '.uniqid());

        $ics = $this->buildIcs('uid-vacation-1', '20260801', '20260804', 'Summer break');
        $result = $this->service->importIcsString($calendar, $ics);

        self::assertSame(3, $result->new);
        self::assertSame(0, $result->updated);
        self::assertSame(0, $result->unchanged);

        $entries = $this->entriesForCalendar($calendar);
        self::assertCount(3, $entries);
        self::assertSame(['2026-08-01', '2026-08-02', '2026-08-03'], array_map(
            static fn (CalendarEntry $e) => $e->getDate()->format('Y-m-d'),
            $entries,
        ));
        foreach ($entries as $entry) {
            self::assertSame('Summer break', $entry->getTitle());
        }
    }

    public function testSingleDayEventWithoutDtendStillCreatesExactlyOneEntry(): void
    {
        $calendar = $this->createCalendar('Waste '.uniqid());

        $ics = $this->buildIcs('uid-single-1', '20260810', null, 'Restmüll');
        $result = $this->service->importIcsString($calendar, $ics);

        self::assertSame(1, $result->new);
        $entries = $this->entriesForCalendar($calendar);
        self::assertCount(1, $entries);
        self::assertSame('2026-08-10', $entries[0]->getDate()->format('Y-m-d'));
    }

    public function testResyncingAnUnchangedMultiDayEventReportsUnchanged(): void
    {
        $calendar = $this->createCalendar('Vacation '.uniqid());
        $ics = $this->buildIcs('uid-vacation-2', '20260901', '20260903', 'Autumn break');

        $this->service->importIcsString($calendar, $ics);
        $this->em->clear();
        $calendar = $this->em->getRepository(Calendar::class)->find($calendar->getId());

        $result = $this->service->importIcsString($calendar, $ics);

        self::assertSame(0, $result->new);
        self::assertSame(0, $result->updated);
        self::assertSame(2, $result->unchanged);
        self::assertCount(2, $this->entriesForCalendar($calendar));
    }

    public function testResyncingAConfirmedEntryDoesNotOverwriteItsTitle(): void
    {
        $calendar = $this->createCalendar('Vacation '.uniqid());
        $ics = $this->buildIcs('uid-confirmed-1', '20261001', null, 'Original title');
        $this->service->importIcsString($calendar, $ics);

        $entry = $this->entriesForCalendar($calendar)[0];
        $entry->setConfirmedAt(new \DateTime());
        $this->em->flush();
        $this->em->clear();
        $calendar = $this->em->getRepository(Calendar::class)->find($calendar->getId());

        // Same UID/date, source title changed - simulates upstream editing
        // the event after staff already confirmed the original.
        $changedIcs = $this->buildIcs('uid-confirmed-1', '20261001', null, 'Changed title');
        $result = $this->service->importIcsString($calendar, $changedIcs);

        self::assertSame(0, $result->new);
        self::assertSame(0, $result->updated);
        self::assertSame(1, $result->unchanged);

        $entries = $this->entriesForCalendar($calendar);
        self::assertCount(1, $entries);
        self::assertSame('Original title', $entries[0]->getTitle());
        self::assertNotNull($entries[0]->getConfirmedAt());
    }

    public function testMovingAnEventUpdatesItsEntryInsteadOfDuplicatingIt(): void
    {
        $calendar = $this->createCalendar('Events '.uniqid());
        $this->service->importIcsString($calendar, $this->buildIcs('uid-moved-1', '20261102', null, 'Wartung'));

        // Same UID, one day later - the source moved the appointment.
        $result = $this->service->importIcsString($calendar, $this->buildIcs('uid-moved-1', '20261103', null, 'Wartung'));

        self::assertSame(0, $result->new, 'a moved event must not count as new');
        self::assertSame(1, $result->updated);

        $entries = $this->entriesForCalendar($calendar);
        self::assertCount(1, $entries, 'the old day must not be left behind');
        self::assertSame('2026-11-03', $entries[0]->getDate()->format('Y-m-d'));
    }

    public function testMovingAMultiDayEventShiftsItsEntriesWithoutOrphans(): void
    {
        $calendar = $this->createCalendar('Events '.uniqid());
        $this->service->importIcsString($calendar, $this->buildIcs('uid-moved-2', '20261201', '20261204', 'Messe'));

        // Whole three-day span shifted forward by one day.
        $result = $this->service->importIcsString($calendar, $this->buildIcs('uid-moved-2', '20261202', '20261205', 'Messe'));

        self::assertSame(0, $result->new);
        $entries = $this->entriesForCalendar($calendar);
        self::assertSame(['2026-12-02', '2026-12-03', '2026-12-04'], array_map(
            static fn (CalendarEntry $e) => $e->getDate()->format('Y-m-d'),
            $entries,
        ));
    }

    public function testShorteningAnEventDropsTheDaysItNoLongerCovers(): void
    {
        $calendar = $this->createCalendar('Events '.uniqid());
        $this->service->importIcsString($calendar, $this->buildIcs('uid-shrink-1', '20270301', '20270304', 'Umbau'));

        $this->service->importIcsString($calendar, $this->buildIcs('uid-shrink-1', '20270301', '20270303', 'Umbau'));

        self::assertCount(2, $this->entriesForCalendar($calendar));
    }

    public function testAConfirmedEntryIsNeitherMovedNorRemovedWhenItsEventMoves(): void
    {
        $calendar = $this->createCalendar('Events '.uniqid());
        $this->service->importIcsString($calendar, $this->buildIcs('uid-moved-3', '20270401', null, 'Abnahme'));

        $entry = $this->entriesForCalendar($calendar)[0];
        $entry->setConfirmedAt(new \DateTime());
        $this->em->flush();
        $this->em->clear();
        $calendar = $this->em->getRepository(Calendar::class)->find($calendar->getId());

        $this->service->importIcsString($calendar, $this->buildIcs('uid-moved-3', '20270402', null, 'Abnahme'));

        $entries = $this->entriesForCalendar($calendar);
        self::assertCount(2, $entries, 'the confirmed day stays, the new day is added alongside it');
        self::assertSame('2027-04-01', $entries[0]->getDate()->format('Y-m-d'));
        self::assertNotNull($entries[0]->getConfirmedAt());
        self::assertSame('2027-04-02', $entries[1]->getDate()->format('Y-m-d'));
    }

    public function testAnEventGoneFromTheFeedIsLeftUntouched(): void
    {
        $calendar = $this->createCalendar('Events '.uniqid());
        $this->service->importIcsString($calendar, $this->buildIcs('uid-gone-1', '20270501', null, 'Alt'));

        // A later feed that no longer mentions that UID at all.
        $this->service->importIcsString($calendar, $this->buildIcs('uid-gone-2', '20270601', null, 'Neu'));

        self::assertCount(2, $this->entriesForCalendar($calendar));
    }

    public function testAbsurdlyLongSpanIsSkippedInsteadOfCreatingThousandsOfEntries(): void
    {
        $calendar = $this->createCalendar('Broken feed '.uniqid());

        $ics = $this->buildIcs('uid-broken-1', '20260101', '20990101', 'Bogus multi-year event');
        $result = $this->service->importIcsString($calendar, $ics);

        self::assertSame(0, $result->total());
        self::assertCount(0, $this->entriesForCalendar($calendar));
    }

    public function testOverlongSummaryIsTruncatedInsteadOfFailingTheFlush(): void
    {
        $calendar = $this->createCalendar('Long summary '.uniqid());

        $summary = str_repeat('a', 250);
        $result = $this->service->importIcsString($calendar, $this->buildIcs('uid-long-summary', '20260901', null, $summary));

        self::assertSame(1, $result->new);
        $entries = $this->entriesForCalendar($calendar);
        self::assertSame(100, mb_strlen($entries[0]->getTitle()));
        self::assertSame(str_repeat('a', 100), $entries[0]->getTitle());
    }

    public function testTruncationCountsCharactersNotBytes(): void
    {
        $calendar = $this->createCalendar('Umlauts '.uniqid());

        // 150 two-byte characters: cutting at 100 bytes would both overshoot
        // the column and risk splitting one in half.
        $result = $this->service->importIcsString($calendar, $this->buildIcs('uid-umlaut', '20260902', null, str_repeat('ü', 150)));

        self::assertSame(1, $result->new);
        self::assertSame(str_repeat('ü', 100), $this->entriesForCalendar($calendar)[0]->getTitle());
    }

    public function testAnOverlongSummaryDoesNotStopTheRemainingEvents(): void
    {
        // The point of truncating at all: a failed flush closes the
        // EntityManager, which would take every later calendar down too.
        $calendar = $this->createCalendar('Mixed feed '.uniqid());

        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:uid-mixed-long',
            'DTSTART;VALUE=DATE:20260905',
            'SUMMARY:'.str_repeat('x', 300),
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:uid-mixed-short',
            'DTSTART;VALUE=DATE:20260906',
            'SUMMARY:Restmüll',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $result = $this->service->importIcsString($calendar, $ics);

        self::assertSame(2, $result->new);
        $titles = array_map(static fn (CalendarEntry $e) => $e->getTitle(), $this->entriesForCalendar($calendar));
        self::assertContains('Restmüll', $titles);
    }

    public function testOverlongUidIsHashedSoItStillFitsTheColumn(): void
    {
        $calendar = $this->createCalendar('Long uid '.uniqid());

        $uid = str_repeat('u', 400);
        $result = $this->service->importIcsString($calendar, $this->buildIcs($uid, '20260903', null, 'Langes UID'));

        self::assertSame(1, $result->new);
        $sourceUid = $this->entriesForCalendar($calendar)[0]->getSourceUid();
        self::assertLessThanOrEqual(255, mb_strlen((string) $sourceUid));

        // Deterministic: syncing the same feed again must match the entry it
        // already created rather than inserting a second one.
        $again = $this->service->importIcsString($calendar, $this->buildIcs($uid, '20260903', null, 'Langes UID'));
        self::assertSame(0, $again->new);
        self::assertSame(1, $again->unchanged);
    }

    public function testTwoDistinctOverlongUidsStayDistinct(): void
    {
        $calendar = $this->createCalendar('Uid collision '.uniqid());

        // Same 400-character prefix, different tail - cutting instead of
        // hashing would collapse these two events onto one entry.
        $prefix = str_repeat('u', 400);
        $this->service->importIcsString($calendar, $this->buildIcs($prefix.'-one', '20260903', null, 'Erstes'));
        $this->service->importIcsString($calendar, $this->buildIcs($prefix.'-two', '20260904', null, 'Zweites'));

        $entries = $this->entriesForCalendar($calendar);
        self::assertCount(2, $entries);
        self::assertNotSame($entries[0]->getSourceUid(), $entries[1]->getSourceUid());
    }

    private function createCalendar(string $name): Calendar
    {
        $calendar = new Calendar();
        $calendar->setName($name);
        $this->em->persist($calendar);
        $this->em->flush();

        return $calendar;
    }

    /** @return CalendarEntry[] */
    private function entriesForCalendar(Calendar $calendar): array
    {
        $entries = static::getContainer()->get(CalendarEntryRepository::class)->findBy(['calendar' => $calendar]);
        usort($entries, static fn (CalendarEntry $a, CalendarEntry $b) => $a->getDate() <=> $b->getDate());

        return $entries;
    }

    private function buildIcs(string $uid, string $dtStart, ?string $dtEnd, string $summary): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:'.$uid,
            'DTSTART;VALUE=DATE:'.$dtStart,
        ];
        if (null !== $dtEnd) {
            $lines[] = 'DTEND;VALUE=DATE:'.$dtEnd;
        }
        $lines[] = 'SUMMARY:'.$summary;
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines);
    }
}
