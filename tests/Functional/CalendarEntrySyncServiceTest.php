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

    public function testAbsurdlyLongSpanIsSkippedInsteadOfCreatingThousandsOfEntries(): void
    {
        $calendar = $this->createCalendar('Broken feed '.uniqid());

        $ics = $this->buildIcs('uid-broken-1', '20260101', '20990101', 'Bogus multi-year event');
        $result = $this->service->importIcsString($calendar, $ics);

        self::assertSame(0, $result->total());
        self::assertCount(0, $this->entriesForCalendar($calendar));
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
