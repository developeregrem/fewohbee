<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Calendar;
use App\Entity\CalendarEntry;
use App\Entity\Role;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The calendar-entry routes guard themselves two ways: ROLE_RESERVATIONS for
 * writing at all, and a CSRF token per state change. Both are the kind of
 * protection whose absence stays invisible - the happy path keeps working
 * while the guard is gone - so both are pinned down here.
 *
 * Valid tokens are read out of the rendered markup rather than minted in the
 * test, which keeps them tied to the session the request will run in and
 * checks along the way that the templates actually emit them.
 */
final class CalendarEntryControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    // ── Confirming reminders ──────────────────────────────────────────

    public function testConfirmingAReminderRecordsWhenAndByWhom(): void
    {
        $client = static::createClient();
        $user = $this->createUserWithRoles(['ROLE_RESERVATIONS']);
        $client->loginUser($user);
        $entry = $this->createEntry();

        $client->request('POST', '/reservation/calendar-reminder/'.$entry->getId().'/confirm', [
            '_token' => $this->confirmTokenFor($client, $entry),
        ]);

        self::assertResponseStatusCodeSame(204);
        $fresh = $this->reload($entry);
        self::assertTrue($fresh->isConfirmed());
        self::assertSame($user->getId(), $fresh->getConfirmedBy()?->getId());
    }

    public function testConfirmingWithoutACsrfTokenChangesNothing(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(['ROLE_RESERVATIONS']));
        $entry = $this->createEntry();

        $client->request('POST', '/reservation/calendar-reminder/'.$entry->getId().'/confirm');

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->reload($entry)->isConfirmed());
    }

    public function testAnotherEntrysTokenCannotConfirmThisOne(): void
    {
        // The token is bound to the entry id, so a token that is perfectly
        // valid for a neighbouring reminder must not work here.
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(['ROLE_RESERVATIONS']));
        $entry = $this->createEntry();
        $other = $this->createEntry();

        $client->request('POST', '/reservation/calendar-reminder/'.$entry->getId().'/confirm', [
            '_token' => $this->confirmTokenFor($client, $other),
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->reload($entry)->isConfirmed());
    }

    public function testUnconfirmingReopensTheReminder(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(['ROLE_RESERVATIONS']));
        $entry = $this->createEntry(confirmed: true);

        $client->request('POST', '/reservation/calendar-reminder/'.$entry->getId().'/unconfirm', [
            '_token' => $this->unconfirmTokenFor($client, $entry),
        ]);

        self::assertResponseStatusCodeSame(204);
        $fresh = $this->reload($entry);
        self::assertFalse($fresh->isConfirmed());
        self::assertNull($fresh->getConfirmedBy());
    }

    public function testUnconfirmingWithoutACsrfTokenLeavesTheConfirmationIntact(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(['ROLE_RESERVATIONS']));
        $entry = $this->createEntry(confirmed: true);

        $client->request('POST', '/reservation/calendar-reminder/'.$entry->getId().'/unconfirm');

        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->reload($entry)->isConfirmed());
    }

    // ── Deleting entries ──────────────────────────────────────────────

    public function testDeletingWithoutACsrfTokenKeepsTheEntry(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(['ROLE_RESERVATIONS']));
        $entry = $this->createEntry();

        $client->request('DELETE', '/reservation/calendar-entry/'.$entry->getId().'/delete');

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->find($entry->getId()));
    }

    public function testDeletingWithAValidTokenRemovesTheEntry(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(['ROLE_RESERVATIONS']));
        $entry = $this->createEntry();
        $id = $entry->getId();

        $client->request('DELETE', '/reservation/calendar-entry/'.$id.'/delete', [
            '_token' => $this->deleteTokenFor($client, $entry),
        ]);

        self::assertResponseRedirects();
        self::assertNull($this->find($id));
    }

    // ── Read-only staff ───────────────────────────────────────────────

    public function testReadOnlyStaffMayOpenTheOverviewAndTheReminderList(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(['ROLE_RESERVATIONS_RO']));

        $client->request('GET', '/reservation/');
        self::assertResponseIsSuccessful();

        // Seeing what still needs confirming is a read; ticking it off is not
        // (the button inside the modal is gated separately).
        $client->request('GET', '/reservation/calendar-reminder');
        self::assertResponseIsSuccessful();
    }

    public function testReadOnlyStaffCannotReachTheWriteRoutes(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(['ROLE_RESERVATIONS_RO']));
        $entry = $this->createEntry();

        $client->request('GET', '/reservation/calendar-entry/new');
        self::assertResponseStatusCodeSame(403, 'creating an entry');

        $client->request('GET', '/reservation/calendar-entry/'.$entry->getId().'/edit');
        self::assertResponseStatusCodeSame(403, 'editing an entry');

        $client->request('DELETE', '/reservation/calendar-entry/'.$entry->getId().'/delete');
        self::assertResponseStatusCodeSame(403, 'deleting an entry');

        $client->request('POST', '/reservation/calendar-reminder/'.$entry->getId().'/confirm');
        self::assertResponseStatusCodeSame(403, 'confirming a reminder');

        self::assertNotNull($this->find($entry->getId()), 'entry survived');
        self::assertFalse($this->reload($entry)->isConfirmed(), 'reminder stayed open');
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /**
     * The reminder modal only lists anything once the overview has calendar
     * entries switched on, which the table request records in the session.
     */
    private function confirmTokenFor(KernelBrowser $client, CalendarEntry $entry): string
    {
        $client->request('GET', '/reservation/table?showCalendarEntries=true&interval=30&object=all');
        $crawler = $client->request('GET', '/reservation/calendar-reminder');

        $button = $crawler->filter('button[data-url$="/'.$entry->getId().'/confirm"]');
        self::assertGreaterThan(0, $button->count(), 'reminder is not listed in the modal');

        return (string) $button->attr('data-token');
    }

    private function unconfirmTokenFor(KernelBrowser $client, CalendarEntry $entry): string
    {
        $crawler = $client->request('GET', '/reservation/calendar-entry/'.$entry->getId().'/edit');
        $button = $crawler->filter('button[data-url$="/unconfirm"]');
        self::assertGreaterThan(0, $button->count(), 'no unconfirm button on the edit form');

        return (string) $button->attr('data-token');
    }

    /** The delete token rides inside the shared popover's markup. */
    private function deleteTokenFor(KernelBrowser $client, CalendarEntry $entry): string
    {
        $crawler = $client->request('GET', '/reservation/calendar-entry/'.$entry->getId().'/edit');
        $content = (string) $crawler->filter('button.js-calendar-entry-delete')->attr('data-bs-content');

        self::assertMatchesRegularExpression('/name="_token" value="[^"]+"/', $content, 'delete popover carries no token');
        preg_match('/name="_token" value="([^"]+)"/', $content, $matches);

        return $matches[1];
    }

    private function createEntry(bool $confirmed = false): CalendarEntry
    {
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();

        $calendar = new Calendar();
        $calendar->setName('Test '.bin2hex(random_bytes(4)));
        $calendar->setRequiresConfirmation(true);
        $em->persist($calendar);

        $entry = new CalendarEntry();
        $entry->setCalendar($calendar);
        $entry->setDate(new \DateTimeImmutable('today'));
        $entry->setTitle('Testeintrag');
        if ($confirmed) {
            $entry->setConfirmedAt(new \DateTime());
        }
        $em->persist($entry);
        $em->flush();

        return $entry;
    }

    private function find(?int $id): ?CalendarEntry
    {
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        $em->clear();

        return $em->getRepository(CalendarEntry::class)->find($id);
    }

    private function reload(CalendarEntry $entry): CalendarEntry
    {
        $fresh = $this->find($entry->getId());
        self::assertNotNull($fresh, 'entry disappeared');

        return $fresh;
    }

    /**
     * @param string[] $roleCodes
     */
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
