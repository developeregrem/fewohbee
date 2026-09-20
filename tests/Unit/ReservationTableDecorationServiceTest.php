<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Calendar;
use App\Entity\CalendarEntry;
use App\Repository\CalendarEntryRepository;
use App\Repository\CalendarRepository;
use App\Service\Calendar\PublicHolidayService;
use App\Service\ReservationTableDecorationService;
use PHPUnit\Framework\TestCase;
use App\Service\Calendar\Entry\CalendarEntryTimeFormatter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

/**
 * What the day headers offer depends on who is looking and on whether there
 * is anything to add an entry to. Both decisions live here rather than in the
 * template, so both are checked here.
 */
final class ReservationTableDecorationServiceTest extends TestCase
{
    private const DAY = '2026-03-02';

    public function testReadOnlyStaffGetNoEditDeleteOrAddLinks(): void
    {
        $decorations = $this->build(canManageEntries: false, calendarCount: 3);

        $day = $decorations[self::DAY];
        self::assertNull($day->newEntryUrl);
        self::assertCount(1, $day->calendarEntries);
        self::assertNull($day->calendarEntries[0]->editUrl);
        self::assertNull($day->calendarEntries[0]->deleteUrl);

        // Still visible - read-only means read, not blind.
        self::assertSame('Restmüll', $day->calendarEntries[0]->title);
    }

    public function testStaffWithWriteAccessGetAllThree(): void
    {
        $day = $this->build(canManageEntries: true, calendarCount: 3)[self::DAY];

        self::assertNotNull($day->newEntryUrl);
        self::assertNotNull($day->calendarEntries[0]->editUrl);
        self::assertNotNull($day->calendarEntries[0]->deleteUrl);
    }

    public function testAddLinkIsWithheldWhenNoCalendarExists(): void
    {
        // The new-entry form 404s without a calendar to file the entry under,
        // so offering the link would just look broken.
        $day = $this->build(canManageEntries: true, calendarCount: 0)[self::DAY];

        self::assertNull($day->newEntryUrl);
    }

    public function testEntriesStayVisibleWithCalendarEntriesTurnedOff(): void
    {
        $day = $this->build(canManageEntries: true, calendarCount: 3, showCalendarEntries: false)[self::DAY];

        self::assertSame([], $day->calendarEntries);
        self::assertNull($day->newEntryUrl);
    }

    /**
     * @return array<string, \App\Dto\ReservationTable\DayDecoration>
     */
    private function build(
        bool $canManageEntries,
        int $calendarCount,
        bool $showCalendarEntries = true,
    ): array {
        $calendar = new Calendar();
        $calendar->setName('Abfall');
        $calendar->setColor('#ff0000');
        self::setId($calendar, 7);

        $entry = new CalendarEntry();
        $entry->setCalendar($calendar);
        $entry->setDate(new \DateTimeImmutable(self::DAY));
        $entry->setTitle('Restmüll');
        self::setId($entry, 42);

        $entryRepo = $this->createStub(CalendarEntryRepository::class);
        $entryRepo->method('findForPeriod')->willReturn([$entry]);

        $calendarRepo = $this->createStub(CalendarRepository::class);
        $calendarRepo->method('count')->willReturn($calendarCount);

        $calendarService = $this->createStub(PublicHolidayService::class);
        $calendarService->method('getPublicdaysForDay')->willReturn([]);

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $route, array $params = []) => '/'.$route.'/'.implode('-', $params)
        );

        $translator = new Translator('de');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', [
            'calendar_entry.time.range' => '%start% - %end%',
            'calendar_entry.time.until' => '- %end%',
        ], 'de');

        $service = new ReservationTableDecorationService(
            $entryRepo,
            $calendarRepo,
            $calendarService,
            $urlGenerator,
            new CalendarEntryTimeFormatter($translator),
        );

        return $service->buildForDays(
            [new \DateTimeImmutable(self::DAY)],
            'DE',
            'de',
            $showCalendarEntries,
            $canManageEntries,
        );
    }

    /** Both entities generate their id, so a test double has to set it directly. */
    private static function setId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);
    }
}
