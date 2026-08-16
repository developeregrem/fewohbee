<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Calendar;
use App\Entity\CalendarEntry;
use App\Entity\Role;
use App\Entity\User;
use App\Repository\CalendarEntryRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Manually created calendar entries: what the form accepts, what it refuses,
 * and how a period is split across days.
 *
 * The rules themselves are unit-tested in CalendarEntryServiceTest; what is
 * checked here is that they actually reach the HTTP layer - that a rejected
 * entry is not persisted and that its message lands on the right field.
 */
final class CalendarEntryFormTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testASingleDayEntryKeepsBothItsTimes(): void
    {
        $client = $this->loggedInClient();
        $calendar = $this->createCalendar();

        $this->submitEntry($client, $calendar, [
            'date' => '2026-09-10',
            'time' => '13:00',
            'endTime' => '14:00',
            'title' => 'Zahnarzt',
        ]);

        $entries = $this->entriesOf($calendar);
        self::assertCount(1, $entries);
        self::assertSame('13:00', $entries[0]->getTime()?->format('H:i'));
        self::assertSame('14:00', $entries[0]->getEndTime()?->format('H:i'));
    }

    public function testLeavingBothTimesEmptyKeepsTheEntryAllDay(): void
    {
        $client = $this->loggedInClient();
        $calendar = $this->createCalendar();

        $this->submitEntry($client, $calendar, [
            'date' => '2026-09-11',
            'title' => 'Sommerferien',
        ]);

        $entries = $this->entriesOf($calendar);
        self::assertCount(1, $entries);
        self::assertNull($entries[0]->getTime());
        self::assertNull($entries[0]->getEndTime());
    }

    /**
     * The same split the ICS import makes: start on the first day, end on the
     * last, the days between running all day.
     */
    public function testAPeriodPutsTheStartOnTheFirstDayAndTheEndOnTheLast(): void
    {
        $client = $this->loggedInClient();
        $calendar = $this->createCalendar();

        $this->submitEntry($client, $calendar, [
            'date' => '2026-09-14',
            'dateTo' => '2026-09-16',
            'time' => '18:00',
            'endTime' => '09:00',
            'title' => 'Handwerker',
        ]);

        $entries = $this->entriesOf($calendar);
        self::assertCount(3, $entries);

        self::assertSame('18:00', $entries[0]->getTime()?->format('H:i'));
        self::assertNull($entries[0]->getEndTime());
        self::assertNull($entries[1]->getTime());
        self::assertNull($entries[1]->getEndTime());
        self::assertNull($entries[2]->getTime());
        self::assertSame('09:00', $entries[2]->getEndTime()?->format('H:i'));
    }

    /**
     * The case Alex's review flagged: the ICS import stores an event running
     * until midnight as 18:00 - 00:00, so the form has to accept exactly that
     * or the two halves of the application contradict each other.
     */
    public function testAnEndAtMidnightIsAcceptedAlongsideAStartTime(): void
    {
        $client = $this->loggedInClient();
        $calendar = $this->createCalendar();

        $this->submitEntry($client, $calendar, [
            'date' => '2026-09-18',
            'time' => '18:00',
            'endTime' => '00:00',
            'title' => 'Abendveranstaltung',
        ]);

        $entries = $this->entriesOf($calendar);
        self::assertCount(1, $entries);
        self::assertSame('18:00', $entries[0]->getTime()?->format('H:i'));
        self::assertSame('00:00', $entries[0]->getEndTime()?->format('H:i'));
    }

    /**
     * A period ending at midnight stored a lone "- 00:00" on its closing day,
     * which the edit form then refused to save unchanged. The closing day runs
     * to its own end anyway, so it is stored all-day.
     */
    public function testAPeriodEndingAtMidnightLeavesItsClosingDayAllDay(): void
    {
        $client = $this->loggedInClient();
        $calendar = $this->createCalendar();

        $this->submitEntry($client, $calendar, [
            'date' => '2026-09-28',
            'dateTo' => '2026-09-30',
            'time' => '18:00',
            'endTime' => '00:00',
            'title' => 'Nachtschicht',
        ]);

        $entries = $this->entriesOf($calendar);
        self::assertCount(3, $entries);
        self::assertSame('18:00', $entries[0]->getTime()?->format('H:i'));
        self::assertNull($entries[2]->getTime());
        self::assertNull($entries[2]->getEndTime());
    }

    /** Whatever the creation form writes, the edit form has to accept back. */
    public function testTheClosingDayOfAMidnightPeriodCanBeSavedAgainUnchanged(): void
    {
        $client = $this->loggedInClient();
        $calendar = $this->createCalendar();

        $this->submitEntry($client, $calendar, [
            'date' => '2026-10-05',
            'dateTo' => '2026-10-06',
            'time' => '18:00',
            'endTime' => '00:00',
            'title' => 'Nachtschicht',
        ]);

        $closingDay = $this->entriesOf($calendar)[1];

        $crawler = $client->request('GET', '/reservation/calendar-entry/'.$closingDay->getId().'/edit');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->filter('form')->form());

        // A rejected entry re-renders the form with the error; a saved one
        // redirects to the overview.
        self::assertResponseRedirects();
        self::assertNull($this->entriesOf($calendar)[1]->getEndTime());
    }

    public function testAnEndBeforeTheStartIsRejectedAndNothingIsSaved(): void
    {
        $client = $this->loggedInClient();
        $calendar = $this->createCalendar();

        $crawler = $this->submitEntry($client, $calendar, [
            'date' => '2026-09-20',
            'time' => '13:00',
            'endTime' => '12:00',
            'title' => 'Verdreht',
        ]);

        self::assertSame([], $this->entriesOf($calendar));
        self::assertStringContainsString('Endzeit', $crawler->filter('form')->text());
    }

    public function testAnEndAtMidnightWithoutAStartTimeIsRejected(): void
    {
        $client = $this->loggedInClient();
        $calendar = $this->createCalendar();

        $this->submitEntry($client, $calendar, [
            'date' => '2026-09-21',
            'endTime' => '00:00',
            'title' => 'Nur Ende',
        ]);

        self::assertSame([], $this->entriesOf($calendar));
    }

    /**
     * A closing day carries only an end time. It belongs at that hour, not
     * lumped in with the all-day entries a plain "ORDER BY time" would sort it
     * among - see CalendarEntryRepository::TIME_OF_DAY_ORDER.
     */
    public function testAClosingDaySortsByItsEndTimeNotWithTheAllDayEntries(): void
    {
        $client = $this->loggedInClient();
        $calendar = $this->createCalendar();
        $day = new \DateTimeImmutable('2026-09-25');

        $this->persistEntry($calendar, $day, null, null, 'Ganztägig');
        $this->persistEntry($calendar, $day, null, '14:00', 'Abschlusstag');
        $this->persistEntry($calendar, $day, '09:00', null, 'Morgens');
        $this->persistEntry($calendar, $day, '18:00', null, 'Abends');

        // findForPeriod spans every calendar, so entries other tests left on
        // this day are filtered out - only the relative order matters here.
        $entries = array_filter(
            static::getContainer()->get(CalendarEntryRepository::class)->findForPeriod($day, $day),
            static fn (CalendarEntry $e): bool => $e->getCalendar()->getId() === $calendar->getId(),
        );

        self::assertSame(
            ['Ganztägig', 'Morgens', 'Abschlusstag', 'Abends'],
            array_values(array_map(static fn (CalendarEntry $e): string => $e->getTitle(), $entries)),
        );
    }

    /**
     * Submits the new-entry form, reading the CSRF token out of the rendered
     * markup so it stays tied to this session.
     *
     * @param array<string, string> $values field name => value; omitted fields stay empty
     */
    private function submitEntry(KernelBrowser $client, Calendar $calendar, array $values): \Symfony\Component\DomCrawler\Crawler
    {
        $crawler = $client->request('GET', '/reservation/calendar-entry/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form();
        $form['calendar_entry[calendar]'] = (string) $calendar->getId();
        foreach (['date', 'dateTo', 'time', 'endTime', 'title'] as $field) {
            if (isset($form['calendar_entry['.$field.']'])) {
                $form['calendar_entry['.$field.']'] = $values[$field] ?? '';
            }
        }

        return $client->submit($form);
    }

    /** @return list<CalendarEntry> */
    private function entriesOf(Calendar $calendar): array
    {
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        $em->clear();

        return $em->getRepository(CalendarEntry::class)->findBy(['calendar' => $calendar->getId()], ['date' => 'ASC']);
    }

    private function persistEntry(Calendar $calendar, \DateTimeImmutable $date, ?string $time, ?string $endTime, string $title): void
    {
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();

        $entry = (new CalendarEntry())
            ->setCalendar($em->getRepository(Calendar::class)->find($calendar->getId()))
            ->setDate($date)
            ->setTitle($title);
        if (null !== $time) {
            $entry->setTime(new \DateTimeImmutable('1970-01-01 '.$time.':00'));
        }
        if (null !== $endTime) {
            $entry->setEndTime(new \DateTimeImmutable('1970-01-01 '.$endTime.':00'));
        }

        $em->persist($entry);
        $em->flush();
    }

    private function createCalendar(): Calendar
    {
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();

        $calendar = new Calendar();
        $calendar->setName('Form '.bin2hex(random_bytes(4)));
        $em->persist($calendar);
        $em->flush();

        return $calendar;
    }

    private function loggedInClient(): KernelBrowser
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(['ROLE_RESERVATIONS']));

        return $client;
    }

    /** @param string[] $roleCodes */
    private function createUserWithRoles(array $roleCodes): User
    {
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();
        $roleRepository = $em->getRepository(Role::class);

        $user = new User();
        $user->setUsername('test_'.bin2hex(random_bytes(6)));
        $user->setFirstname('Test');
        $user->setLastname('User');
        $user->setEmail(sprintf('test+%s@example.com', bin2hex(random_bytes(4))));
        $user->setActive(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'ChangeMe123!'));

        $roles = [];
        foreach ($roleCodes as $roleCode) {
            $role = $roleRepository->findOneBy(['role' => $roleCode]);
            self::assertNotNull($role, sprintf('Role %s must exist in database.', $roleCode));
            $roles[] = $role;
        }
        $user->setRoleEntities($roles);

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
