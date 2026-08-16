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
    private string $originalTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        $this->service = static::getContainer()->get(CalendarEntrySyncService::class);

        // The import reads feed instants in the application timezone, which is
        // PHP's date.timezone. Pinned here instead of inherited so these
        // expectations do not depend on the php.ini of whoever runs them -
        // a CLI without the setting lands on UTC and would shift every
        // assertion below by two hours.
        $this->originalTimezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimezone);
        parent::tearDown();
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
        self::assertSame(1, $result->skippedInvalid);
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

    public function testRecurringEventIsSkippedAndCountedInsteadOfLandingOnItsFirstDate(): void
    {
        $calendar = $this->createCalendar('Geburtstage '.uniqid());

        // How a birthday actually looks in an exported feed: one VEVENT on the
        // year of birth, repeating via RRULE. Importing DTSTART alone would
        // file it decades in the past, where nobody would ever see it.
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:geburtstag-anna',
            'DTSTART;VALUE=DATE:19800315',
            'RRULE:FREQ=YEARLY',
            'SUMMARY:Geburtstag Anna',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $result = $this->service->importIcsString($calendar, $ics);

        self::assertSame(1, $result->skippedRecurring);
        self::assertSame(0, $result->total());
        self::assertCount(0, $this->entriesForCalendar($calendar));
    }

    public function testANonRecurringEventInTheSameFeedStillImports(): void
    {
        $calendar = $this->createCalendar('Gemischt '.uniqid());

        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:wiederkehrend',
            'DTSTART;VALUE=DATE:19800315',
            'RRULE:FREQ=YEARLY',
            'SUMMARY:Geburtstag Anna',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:einmalig',
            'DTSTART;VALUE=DATE:20260910',
            'SUMMARY:Restmüll',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $result = $this->service->importIcsString($calendar, $ics);

        self::assertSame(1, $result->skippedRecurring);
        self::assertSame(1, $result->new);
        self::assertSame('Restmüll', $this->entriesForCalendar($calendar)[0]->getTitle());
    }

    public function testAFeedWithoutRecurrenceReportsNothingSkipped(): void
    {
        $calendar = $this->createCalendar('Ohne Serie '.uniqid());

        $result = $this->service->importIcsString($calendar, $this->buildIcs('uid-plain', '20260911', null, 'Papier'));

        self::assertSame(0, $result->skippedRecurring);
        self::assertSame(1, $result->new);
    }

    public function testTimedEventStoresItsStartTime(): void
    {
        $calendar = $this->createCalendar('Maintenance '.uniqid());

        $ics = $this->buildTimedIcs('uid-timed-1', '20261001T140000', null, 'Wartung Heizung');
        $this->service->importIcsString($calendar, $ics);

        $entries = $this->entriesForCalendar($calendar);
        self::assertCount(1, $entries);
        self::assertSame('14:00', $entries[0]->getTime()?->format('H:i'));
        self::assertTrue($entries[0]->hasTime());
    }

    public function testAllDayEventStoresNoTime(): void
    {
        $calendar = $this->createCalendar('Waste '.uniqid());

        $ics = $this->buildIcs('uid-allday-1', '20261002', null, 'Restmüll');
        $this->service->importIcsString($calendar, $ics);

        $entries = $this->entriesForCalendar($calendar);
        self::assertCount(1, $entries);
        self::assertNull($entries[0]->getTime());
        self::assertFalse($entries[0]->hasTime());
    }

    public function testMultiDayTimedEventPutsTheStartOnTheFirstDayAndTheEndOnTheLast(): void
    {
        $calendar = $this->createCalendar('Seminar '.uniqid());

        // A DTEND carrying a time is the moment the event stops, so Oct 7 is
        // still covered - unlike an all-day DTEND, which is exclusive.
        $ics = $this->buildTimedIcs('uid-timed-span', '20261005T090000', '20261007T170000', 'Seminar');
        $this->service->importIcsString($calendar, $ics);

        $entries = $this->entriesForCalendar($calendar);
        self::assertCount(3, $entries);
        self::assertSame(['2026-10-05', '2026-10-06', '2026-10-07'], array_map(
            static fn (CalendarEntry $e) => $e->getDate()->format('Y-m-d'),
            $entries,
        ));

        self::assertSame('09:00', $entries[0]->getTime()?->format('H:i'));
        self::assertNull($entries[0]->getEndTime(), 'the first day does not end the event');

        // The day the event merely runs through is a whole day.
        self::assertNull($entries[1]->getTime());
        self::assertNull($entries[1]->getEndTime());

        self::assertNull($entries[2]->getTime(), 'the closing day does not start the event');
        self::assertSame('17:00', $entries[2]->getEndTime()?->format('H:i'));
    }

    public function testTimedEventEndingAtMidnightDoesNotClaimTheFollowingDay(): void
    {
        $calendar = $this->createCalendar('Nachtschicht '.uniqid());

        // Stops exactly as Oct 9 begins, so only Oct 8 is covered.
        $ics = $this->buildTimedIcs('uid-midnight', '20261008T180000', '20261009T000000', 'Abendveranstaltung');
        $this->service->importIcsString($calendar, $ics);

        $entries = $this->entriesForCalendar($calendar);
        self::assertCount(1, $entries);
        self::assertSame('2026-10-08', $entries[0]->getDate()->format('Y-m-d'));
        self::assertSame('18:00', $entries[0]->getTime()?->format('H:i'));
        self::assertSame('00:00', $entries[0]->getEndTime()?->format('H:i'));
    }

    public function testSingleDayTimedEventKeepsBothItsTimes(): void
    {
        $calendar = $this->createCalendar('Termin '.uniqid());

        $ics = $this->buildTimedIcs('uid-both-times', '20261013T130000', '20261013T140000', 'Zahnarzt');
        $this->service->importIcsString($calendar, $ics);

        $entries = $this->entriesForCalendar($calendar);
        self::assertCount(1, $entries);
        self::assertSame('13:00', $entries[0]->getTime()?->format('H:i'));
        self::assertSame('14:00', $entries[0]->getEndTime()?->format('H:i'));
    }

    public function testZeroLengthEventIsDiscardedAndCounted(): void
    {
        $calendar = $this->createCalendar('Erinnerung '.uniqid());

        // RFC 5545 requires DTEND to be later than DTSTART. Feeds write an
        // equal one both for a zero-length reminder and, wrongly, for a
        // single all-day event - guessing which was meant would invent a
        // period the source never stated, so the event is dropped and the
        // user told about it.
        $ics = $this->buildTimedIcs('uid-zero-length', '20261014T100000', '20261014T100000', 'Erinnerung');
        $result = $this->service->importIcsString($calendar, $ics);

        self::assertSame([], $this->entriesForCalendar($calendar));
        self::assertSame(1, $result->skippedInvalid);
    }

    public function testUtcFeedTimesAreConvertedToTheApplicationZone(): void
    {
        $calendar = $this->createCalendar('UTC-Feed '.uniqid());

        // How Google publishes a timed event: an explicit UTC instant. In
        // Europe/Berlin (CEST, UTC+2 in August) 11:00Z is 13:00 local - the
        // shift that made a 13:00 appointment show up as 11:00.
        $ics = $this->buildUtcIcs('uid-utc-1', '20260814T110000', '20260816T120000', 'Mehrtägig mit Uhrzeit');
        $this->service->importIcsString($calendar, $ics);

        $entries = $this->entriesForCalendar($calendar);
        self::assertSame(['2026-08-14', '2026-08-15', '2026-08-16'], array_map(
            static fn (CalendarEntry $e) => $e->getDate()->format('Y-m-d'),
            $entries,
        ));
        self::assertSame('13:00', $entries[0]->getTime()?->format('H:i'));
        self::assertSame('14:00', $entries[2]->getEndTime()?->format('H:i'));
    }

    public function testTheZoneComesFromThePhpConfiguration(): void
    {
        // The application has exactly one timezone source, PHP's
        // date.timezone - the same one Doctrine hydrates zone-less DATETIME
        // columns in. Changing it must therefore change how a feed is read;
        // if this ever stops being true, a second source has crept in.
        date_default_timezone_set('America/New_York');

        $calendar = $this->createCalendar('Zonenwechsel '.uniqid());
        // 20:00Z is 16:00 in New York and would be 22:00 in Berlin.
        $ics = $this->buildUtcIcs('uid-zone-setting', '20260818T200000', null, 'Abendtermin');
        $this->service->importIcsString($calendar, $ics);

        $entries = $this->entriesForCalendar($calendar);
        self::assertCount(1, $entries);
        self::assertSame('16:00', $entries[0]->getTime()?->format('H:i'));
    }

    public function testDtEndBeforeDtStartIsDiscardedAndCounted(): void
    {
        $calendar = $this->createCalendar('Rückwärts '.uniqid());

        $ics = $this->buildTimedIcs('uid-reversed', '20261020T130000', '20261020T120000', 'Verdreht');
        $result = $this->service->importIcsString($calendar, $ics);

        self::assertSame([], $this->entriesForCalendar($calendar));
        self::assertSame(1, $result->skippedInvalid);
    }

    public function testADiscardedEventDoesNotStopTheRestOfTheFeed(): void
    {
        $calendar = $this->createCalendar('Gemischt '.uniqid());

        $broken = $this->buildTimedIcs('uid-broken', '20261021T130000', '20261021T120000', 'Kaputt');
        $good = $this->buildTimedIcs('uid-good', '20261022T090000', '20261022T100000', 'Heizungswartung');
        // Splice the two single-event calendars into one feed.
        $ics = str_replace('END:VCALENDAR', substr($good, strpos($good, 'BEGIN:VEVENT')), $broken);

        $result = $this->service->importIcsString($calendar, $ics);

        $entries = $this->entriesForCalendar($calendar);
        self::assertCount(1, $entries);
        self::assertSame('Heizungswartung', $entries[0]->getTitle());
        self::assertSame(1, $result->skippedInvalid);
        self::assertSame(1, $result->new);
    }

    public function testMultiDayEventEndingAtMidnightLeavesItsLastDayAllDay(): void
    {
        $calendar = $this->createCalendar('Über Nacht '.uniqid());

        // Oct 24 runs from its own midnight to the next, so it has no end time
        // worth stating - a lone "- 00:00" would read as ending at the day's
        // beginning instead of closing it.
        $ics = $this->buildTimedIcs('uid-multi-midnight', '20261023T180000', '20261025T000000', 'Nachtschicht');
        $this->service->importIcsString($calendar, $ics);

        $entries = $this->entriesForCalendar($calendar);
        self::assertCount(2, $entries);
        self::assertSame('18:00', $entries[0]->getTime()?->format('H:i'));
        self::assertNull($entries[1]->getTime());
        self::assertNull($entries[1]->getEndTime());
    }

    public function testUtcFeedTimeThatCrossesMidnightLandsOnTheLocalDay(): void
    {
        $calendar = $this->createCalendar('Mitternacht '.uniqid());

        // 22:30Z on Aug 14 is 00:30 on Aug 15 in Europe/Berlin: the zone
        // decides the calendar day, not just the clock time.
        $ics = $this->buildUtcIcs('uid-utc-2', '20260814T223000', '20260814T233000', 'Späte Anreise');
        $this->service->importIcsString($calendar, $ics);

        $entries = $this->entriesForCalendar($calendar);
        self::assertCount(1, $entries);
        self::assertSame('2026-08-15', $entries[0]->getDate()->format('Y-m-d'));
        self::assertSame('00:30', $entries[0]->getTime()?->format('H:i'));
    }

    public function testForeignZoneIsHonouredRatherThanReadAsLocal(): void
    {
        $calendar = $this->createCalendar('Fremde Zone '.uniqid());

        // 09:00 New York is 15:00 Berlin (both on DST in August). Dropping the
        // TZID would store the bare 09:00 instead.
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:uid-ny',
            'DTSTART;TZID=America/New_York:20260817T090000',
            'DTEND;TZID=America/New_York:20260817T100000',
            'SUMMARY:Call',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);
        $this->service->importIcsString($calendar, $ics);

        $entries = $this->entriesForCalendar($calendar);
        self::assertCount(1, $entries);
        self::assertSame('15:00', $entries[0]->getTime()?->format('H:i'));
        self::assertSame('16:00', $entries[0]->getEndTime()?->format('H:i'));
    }

    public function testAllDayDtendStaysExclusive(): void
    {
        $calendar = $this->createCalendar('Ganztägig '.uniqid());

        // Guards the asymmetry: the inclusive handling above must not leak
        // into all-day events, where RFC 5545 makes DTEND exclusive.
        $this->service->importIcsString($calendar, $this->buildIcs('uid-allday-span', '20260801', '20260804', 'Urlaub'));

        $entries = $this->entriesForCalendar($calendar);
        self::assertCount(3, $entries);
        self::assertSame('2026-08-03', $entries[2]->getDate()->format('Y-m-d'));
        self::assertNull($entries[2]->getEndTime());
    }

    public function testResyncingATimedEventReportsUnchanged(): void
    {
        $calendar = $this->createCalendar('Maintenance '.uniqid());
        $ics = $this->buildTimedIcs('uid-timed-2', '20261010T081500', null, 'Wartung');

        $this->service->importIcsString($calendar, $ics);
        $this->em->clear();
        $calendar = $this->em->getRepository(Calendar::class)->find($calendar->getId());

        $result = $this->service->importIcsString($calendar, $ics);

        // Guards the wall-clock comparison: comparing the objects themselves
        // would report every timed entry as updated on each run.
        self::assertSame(0, $result->new);
        self::assertSame(0, $result->updated);
        self::assertSame(1, $result->unchanged);
    }

    public function testChangedTimeUpdatesTheExistingEntry(): void
    {
        $calendar = $this->createCalendar('Maintenance '.uniqid());

        $this->service->importIcsString($calendar, $this->buildTimedIcs('uid-timed-3', '20261011T100000', null, 'Wartung'));
        $this->em->clear();
        $calendar = $this->em->getRepository(Calendar::class)->find($calendar->getId());

        $result = $this->service->importIcsString($calendar, $this->buildTimedIcs('uid-timed-3', '20261011T113000', null, 'Wartung'));

        self::assertSame(1, $result->updated);
        $entries = $this->entriesForCalendar($calendar);
        self::assertCount(1, $entries);
        self::assertSame('11:30', $entries[0]->getTime()?->format('H:i'));
    }

    public function testAnEventLosingItsTimeBecomesAllDayAgain(): void
    {
        $calendar = $this->createCalendar('Maintenance '.uniqid());

        $this->service->importIcsString($calendar, $this->buildTimedIcs('uid-timed-4', '20261012T100000', null, 'Wartung'));
        $this->em->clear();
        $calendar = $this->em->getRepository(Calendar::class)->find($calendar->getId());

        $result = $this->service->importIcsString($calendar, $this->buildIcs('uid-timed-4', '20261012', null, 'Wartung'));

        self::assertSame(1, $result->updated);
        self::assertNull($this->entriesForCalendar($calendar)[0]->getTime());
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

    /**
     * A timed event, i.e. one whose DTSTART carries a clock time rather than
     * the bare date buildIcs() writes. Zone-qualified the way calendar
     * applications publish local appointments.
     */
    private function buildTimedIcs(string $uid, string $dtStart, ?string $dtEnd, string $summary): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:'.$uid,
            'DTSTART;TZID=Europe/Berlin:'.$dtStart,
        ];
        if (null !== $dtEnd) {
            $lines[] = 'DTEND;TZID=Europe/Berlin:'.$dtEnd;
        }
        $lines[] = 'SUMMARY:'.$summary;
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines);
    }

    /**
     * A timed event published as an explicit UTC instant (trailing Z), which
     * is how Google's iCal export writes appointments - the form that has to
     * be converted into the application zone before it means anything.
     */
    private function buildUtcIcs(string $uid, string $dtStart, ?string $dtEnd, string $summary): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:'.$uid,
            'DTSTART:'.$dtStart.'Z',
        ];
        if (null !== $dtEnd) {
            $lines[] = 'DTEND:'.$dtEnd.'Z';
        }
        $lines[] = 'SUMMARY:'.$summary;
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines);
    }
}
