<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Calendar;
use App\Entity\CalendarEntry;
use App\Repository\CalendarEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The calendar management list needs every calendar's entry count at once. */
final class CalendarEntryCountTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CalendarEntryRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        $this->repo = static::getContainer()->get(CalendarEntryRepository::class);
    }

    public function testCountsAreGroupedPerCalendar(): void
    {
        $withTwo = $this->createCalendar('Zwei '.uniqid());
        $withOne = $this->createCalendar('Eins '.uniqid());

        $this->createEntry($withTwo, '2026-04-01');
        $this->createEntry($withTwo, '2026-04-02');
        $this->createEntry($withOne, '2026-04-03');
        $this->em->flush();

        $counts = $this->repo->countGroupedByCalendar();

        self::assertSame(2, $counts[$withTwo->getId()]);
        self::assertSame(1, $counts[$withOne->getId()]);
    }

    public function testACalendarWithoutEntriesIsAbsentRatherThanZero(): void
    {
        // The template falls back to 0 for a missing key; a grouped query
        // cannot produce a row for a calendar that has no entries at all.
        $empty = $this->createCalendar('Leer '.uniqid());
        $this->em->flush();

        self::assertArrayNotHasKey($empty->getId(), $this->repo->countGroupedByCalendar());
    }

    private function createCalendar(string $name): Calendar
    {
        $calendar = new Calendar();
        $calendar->setName($name);
        $this->em->persist($calendar);
        $this->em->flush();

        return $calendar;
    }

    private function createEntry(Calendar $calendar, string $date): void
    {
        $entry = new CalendarEntry();
        $entry->setCalendar($calendar);
        $entry->setDate(new \DateTimeImmutable($date));
        $entry->setTitle('Eintrag '.$date);
        $this->em->persist($entry);
    }
}
