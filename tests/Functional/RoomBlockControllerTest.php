<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Appartment;
use App\Entity\Reservation;
use App\Entity\ReservationStatus;
use App\Entity\Role;
use App\Entity\RoomBlock;
use App\Entity\Subsidiary;
use App\Entity\User;
use App\Service\CalendarService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

final class RoomBlockControllerTest extends WebTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testListRequiresAuthentication(): void
    {
        $client = static::createClient();

        $client->request('GET', '/reservation/blocks/');

        self::assertResponseRedirects();
    }

    public function testListIsAccessibleForReadOnlyRole(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(['ROLE_RESERVATIONS_RO']));

        $client->request('GET', '/reservation/blocks/');

        self::assertResponseIsSuccessful();
    }

    public function testFormIsForbiddenForReadOnlyRole(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(['ROLE_RESERVATIONS_RO']));

        $client->request('GET', '/reservation/blocks/form');

        self::assertResponseStatusCodeSame(403);
    }

    public function testCreateBlocksForMultipleRooms(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(['ROLE_RESERVATIONS']));
        $roomA = $this->createApartment();
        $roomB = $this->createApartment();

        $client->request('POST', '/reservation/blocks/create', [
            '_token' => $this->formToken($client, '/reservation/blocks/form'),
            'rooms' => [$roomA->getId(), $roomB->getId()],
            'from' => '2031-08-01',
            'end' => '2031-08-05',
            'reason' => 'Renovierung',
            'note' => 'Bad',
        ]);

        // success returns 204 so the client reloads the index page
        self::assertResponseStatusCodeSame(204);
        // scope by room: the functional DB is shared across the run
        self::assertCount(1, $this->getEntityManager()->getRepository(RoomBlock::class)->findBy(['appartment' => $roomA]));
        self::assertCount(1, $this->getEntityManager()->getRepository(RoomBlock::class)->findBy(['appartment' => $roomB]));
    }

    public function testCreateIsRejectedWithoutCsrfToken(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(['ROLE_RESERVATIONS']));
        $room = $this->createApartment();

        $client->request('POST', '/reservation/blocks/create', [
            'rooms' => [$room->getId()],
            'from' => '2031-08-20',
            'end' => '2031-08-22',
            'reason' => 'Ohne-Token',
        ]);

        self::assertResponseIsSuccessful(); // form re-rendered
        self::assertCount(0, $this->getEntityManager()->getRepository(RoomBlock::class)->findBy(['appartment' => $room]));
    }

    public function testCreateIsRejectedWhenBlockingReservationOverlaps(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(['ROLE_RESERVATIONS']));
        $room = $this->createApartment();
        $this->createReservation($room, '2031-09-02', '2031-09-06');

        $client->request('POST', '/reservation/blocks/create', [
            '_token' => $this->formToken($client, '/reservation/blocks/form'),
            'rooms' => [$room->getId()],
            'from' => '2031-09-01',
            'end' => '2031-09-05',
            'reason' => 'Wasserschaden',
        ]);

        self::assertResponseIsSuccessful(); // form re-rendered with conflict list
        self::assertStringContainsString('Konflikt', (string) $client->getResponse()->getContent());
        $blocks = $this->getEntityManager()->getRepository(RoomBlock::class)->findBy(['appartment' => $room]);
        self::assertCount(0, $blocks, 'No block must be persisted on conflict');
    }

    public function testBlockStartingOnDepartureDayIsAllowed(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(['ROLE_RESERVATIONS']));
        $room = $this->createApartment();
        $this->createReservation($room, '2031-10-01', '2031-10-05');

        $client->request('POST', '/reservation/blocks/create', [
            '_token' => $this->formToken($client, '/reservation/blocks/form'),
            'rooms' => [$room->getId()],
            'from' => '2031-10-05',
            'end' => '2031-10-08',
            'reason' => 'Turnover-Sperre',
        ]);

        self::assertResponseStatusCodeSame(204);
        $blocks = $this->getEntityManager()->getRepository(RoomBlock::class)->findBy(['appartment' => $room]);
        self::assertCount(1, $blocks, 'Same-day turnover block must be allowed');
    }

    public function testEditAndDeleteBlock(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(['ROLE_RESERVATIONS']));
        $room = $this->createApartment();
        $block = $this->createBlock($room, '2031-11-01', '2031-11-04', 'Editier-Test');

        // edit (CSRF token comes from the edit form on the details page)
        $client->request('POST', sprintf('/reservation/blocks/%d/edit', $block->getId()), [
            '_token' => $this->formToken($client, sprintf('/reservation/blocks/%d', $block->getId())),
            'from' => '2031-11-02',
            'end' => '2031-11-06',
            'reason' => 'Editiert',
        ]);
        self::assertResponseIsSuccessful();
        $this->getEntityManager()->clear();
        $reloaded = $this->getEntityManager()->getRepository(RoomBlock::class)->find($block->getId());
        self::assertSame('Editiert', $reloaded->getReason());
        self::assertSame('2031-11-02', $reloaded->getStartDate()->format('Y-m-d'));

        // delete (CSRF-protected): the delete token lives inside the escaped data-bs-content
        // popover attribute (not a DOM node). The decoded details HTML holds two _token inputs —
        // the edit form's first, the delete popover's last.
        $client->request('GET', sprintf('/reservation/blocks/%d', $block->getId()));
        self::assertResponseIsSuccessful();
        $html = html_entity_decode((string) $client->getResponse()->getContent(), ENT_QUOTES);
        self::assertGreaterThanOrEqual(2, preg_match_all('/name="_token"\s+value="([^"]+)"/', $html, $all));
        $deleteToken = end($all[1]);
        $client->request('DELETE', sprintf('/reservation/blocks/%d/delete', $block->getId()), ['_token' => $deleteToken]);
        self::assertResponseIsSuccessful();
        $this->getEntityManager()->clear();
        self::assertNull($this->getEntityManager()->getRepository(RoomBlock::class)->find($block->getId()));
    }

    public function testBulkDeleteRemovesSelectedBlocks(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(['ROLE_RESERVATIONS']));
        $roomA = $this->createApartment();
        $roomB = $this->createApartment();
        $b1 = $this->createBlock($roomA, '2036-04-01', '2036-04-05', 'Bulk-A');
        $b2 = $this->createBlock($roomB, '2036-04-01', '2036-04-05', 'Bulk-B');

        $client->request('POST', '/reservation/blocks/bulk-delete', [
            '_token' => $this->formToken($client, '/reservation/blocks/form'),
            'ids' => [$b1->getId(), $b2->getId()],
        ]);

        self::assertResponseIsSuccessful();
        $this->getEntityManager()->clear();
        self::assertNull($this->getEntityManager()->getRepository(RoomBlock::class)->find($b1->getId()));
        self::assertNull($this->getEntityManager()->getRepository(RoomBlock::class)->find($b2->getId()));
    }

    public function testBulkDeleteRejectedWithoutCsrfToken(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(['ROLE_RESERVATIONS']));
        $room = $this->createApartment();
        $block = $this->createBlock($room, '2036-05-01', '2036-05-05', 'Bulk-NoToken');

        $client->request('POST', '/reservation/blocks/bulk-delete', ['ids' => [$block->getId()]]);

        self::assertResponseIsSuccessful();
        $this->getEntityManager()->clear();
        self::assertNotNull($this->getEntityManager()->getRepository(RoomBlock::class)->find($block->getId()));
    }

    public function testListFilterByYearAndMonth(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(['ROLE_RESERVATIONS']));
        $room = $this->createApartment();
        // unique year so other tests' blocks don't interfere
        $this->createBlock($room, '2035-03-10', '2035-03-15', 'Filter-Match');

        $client->request('GET', '/reservation/blocks/', ['year' => 2035]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Filter-Match', (string) $client->getResponse()->getContent());

        $client->request('GET', '/reservation/blocks/', ['year' => 2034]);
        self::assertStringNotContainsString('Filter-Match', (string) $client->getResponse()->getContent());

        $client->request('GET', '/reservation/blocks/', ['year' => 2035, 'month' => 3]);
        self::assertStringContainsString('Filter-Match', (string) $client->getResponse()->getContent());

        $client->request('GET', '/reservation/blocks/', ['year' => 2035, 'month' => 7]);
        self::assertStringNotContainsString('Filter-Match', (string) $client->getResponse()->getContent());
    }

    public function testYearlyViewRendersBlockedCell(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(['ROLE_RESERVATIONS']));
        $room = $this->createApartment();
        $this->createBlock($room, '2037-06-10', '2037-06-14', 'Yearly-Grid');

        $client->request('GET', '/reservation/table', [
            'year' => 2037,
            'apartment' => $room->getId(),
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('month-reservation-blocked', (string) $client->getResponse()->getContent());
    }

    public function testReservationTableRendersBlockedCell(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(['ROLE_RESERVATIONS']));
        $room = $this->createApartment();
        $this->createBlock($room, '2031-12-02', '2031-12-05', 'Grid-Test');

        $client->request('GET', '/reservation/table', [
            'start' => '2031-12-01',
            'interval' => 10,
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('td-blocked', (string) $client->getResponse()->getContent());
    }

    public function testIcalExportContainsBlockedEvent(): void
    {
        static::createClient();
        $room = $this->createApartment();
        $block = $this->createBlock($room, '2032-01-10', '2032-01-15', 'Export-Test');

        $sync = new \App\Entity\CalendarSync();
        $sync->setUuid(Uuid::v4());
        $sync->setApartment($room);
        $this->getEntityManager()->persist($sync);
        $this->getEntityManager()->flush();

        $content = static::getContainer()->get(CalendarService::class)->getIcalContent($sync);

        self::assertStringContainsString('SUMMARY:Blocked', $content);
        self::assertStringContainsString('DTSTART;VALUE=DATE:20320110', $content);
        self::assertStringContainsString('DTEND;VALUE=DATE:20320115', $content);
        self::assertStringContainsString($block->getUuid()->toBase32().'@fewohbee', $content);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /** Fetch the create/edit CSRF token from the first real _token input of the rendered form. */
    private function formToken(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $url): string
    {
        $crawler = $client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('input[name="_token"]')->first()->attr('value');
    }

    private function createApartment(): Appartment
    {
        $em = $this->getEntityManager();
        $subsidiary = $em->getRepository(Subsidiary::class)->findOneBy([]);
        if (null === $subsidiary) {
            $subsidiary = new Subsidiary();
            $subsidiary->setName('Testhaus');
            $subsidiary->setDescription('Testhaus');
            $em->persist($subsidiary);
        }

        $apartment = new Appartment();
        $apartment->setNumber('T'.random_int(100, 999).random_int(100, 999));
        $apartment->setBedsMax(2);
        $apartment->setDescription('Testzimmer');
        $apartment->setObject($subsidiary);
        $em->persist($apartment);
        $em->flush();

        return $apartment;
    }

    private function createReservation(Appartment $room, string $start, string $end): Reservation
    {
        $em = $this->getEntityManager();
        $status = $em->getRepository(ReservationStatus::class)->findOneBy(['isBlocking' => true]);
        if (null === $status) {
            $status = new ReservationStatus();
            $status->setName('Test-Blocking');
            $status->setColor('#000000');
            $status->setContrastColor('#ffffff');
            $status->setIsBlocking(true);
            $em->persist($status);
        }

        $reservation = new Reservation();
        $reservation->setAppartment($room);
        $reservation->setStartDate(new \DateTime($start));
        $reservation->setEndDate(new \DateTime($end));
        $reservation->setPersons(2);
        $reservation->setReservationStatus($status);
        $reservation->setUuid(Uuid::v4());
        $em->persist($reservation);
        $em->flush();

        return $reservation;
    }

    private function createBlock(Appartment $room, string $start, string $end, string $reason): RoomBlock
    {
        $block = new RoomBlock();
        $block->setAppartment($room)
            ->setStartDate(new \DateTimeImmutable($start))
            ->setEndDate(new \DateTimeImmutable($end))
            ->setReason($reason);
        $this->getEntityManager()->persist($block);
        $this->getEntityManager()->flush();

        return $block;
    }

    private function createUserWithRoles(array $roleCodes): User
    {
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();
        $roleRepository = $em->getRepository(Role::class);
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setUsername('test_'.bin2hex(random_bytes(6)));
        $user->setFirstname('Test');
        $user->setLastname('User');
        $user->setEmail(sprintf('test+%s@example.com', bin2hex(random_bytes(4))));
        $user->setActive(true);
        $user->setPassword($passwordHasher->hashPassword($user, 'ChangeMe123!'));

        $roles = [];
        foreach ($roleCodes as $roleCode) {
            $role = $roleRepository->findOneBy(['role' => $roleCode]);
            if (null !== $role) {
                $roles[] = $role;
            }
        }
        $user->setRoleEntities($roles);

        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function getEntityManager(): EntityManagerInterface
    {
        if (!isset($this->em)) {
            $this->em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        }

        return $this->em;
    }
}
