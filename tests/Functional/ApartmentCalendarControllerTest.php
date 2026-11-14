<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\CalendarSync;
use App\Entity\Role;
use App\Entity\RoomBlock;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\Routing\RouterInterface;

final class ApartmentCalendarControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testGetCalendarReturnsNotFoundForPrivateSync(): void
    {
        $client = static::createClient();
        $sync = $this->getCalendarSync();
        $sync->setIsPublic(false);
        $this->flush($sync);

        $client->request('GET', $this->generateCalendarPath((string) $sync->getUuid()));

        self::assertResponseStatusCodeSame(404);
    }

    public function testGetCalendarReturnsIcsForPublicSync(): void
    {
        $client = static::createClient();
        $sync = $this->getCalendarSync();
        $sync->setIsPublic(true);
        $this->flush($sync);

        $client->request('GET', $this->generateCalendarPath((string) $sync->getUuid()));

        $response = $client->getResponse();
        self::assertResponseIsSuccessful();
        self::assertSame('text/calendar; charset=utf-8', $response->headers->get('content-type'));
        self::assertStringContainsString('attachment; filename=', (string) $response->headers->get('Content-Disposition'));
        self::assertNotSame('', $response->getContent());
    }

    public function testAdminCanEnableAndDisableReservationTimeExport(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        $admin = $em->getRepository(User::class)->findOneBy(['username' => 'test-admin']);
        self::assertNotNull($admin);
        $client->loginUser($admin, 'main');
        $sync = $this->getCalendarSync();
        self::assertFalse($sync->getExportReservationTimes());
        $path = '/settings/apartments/sync/'.$sync->getId().'/edit';

        $crawler = $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#calendar_sync_export_exportReservationTimes:not(:checked)');
        $form = $crawler->filter('form[name="calendar_sync_export"]')->form();
        $checkbox = $form['calendar_sync_export[exportReservationTimes]'];
        self::assertInstanceOf(ChoiceFormField::class, $checkbox);
        $checkbox->tick();
        $client->submit($form);

        self::assertResponseStatusCodeSame(204);
        static::getContainer()->get(ManagerRegistry::class)->getManager()->clear();
        self::assertTrue($this->getCalendarSync()->getExportReservationTimes());

        $crawler = $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#calendar_sync_export_exportReservationTimes:checked');
        $form = $crawler->filter('form[name="calendar_sync_export"]')->form();
        $checkbox = $form['calendar_sync_export[exportReservationTimes]'];
        self::assertInstanceOf(ChoiceFormField::class, $checkbox);
        $checkbox->untick();
        $client->submit($form);

        self::assertResponseStatusCodeSame(204);
        static::getContainer()->get(ManagerRegistry::class)->getManager()->clear();
        self::assertFalse($this->getCalendarSync()->getExportReservationTimes());
    }

    public function testReadOnlyUserCannotEnableReservationTimeExport(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        $role = $em->getRepository(Role::class)->findOneBy(['role' => 'ROLE_RESERVATIONS_RO']);
        self::assertNotNull($role);
        $user = (new User())
            ->setUsername('calendar_reader_'.bin2hex(random_bytes(6)))
            ->setFirstname('Calendar')
            ->setLastname('Reader')
            ->setEmail('calendar-reader@example.com')
            ->setActive(true)
            ->setPassword('unused');
        $user->setRoleEntities([$role]);
        $em->persist($user);
        $em->flush();
        $client->loginUser($user, 'main');
        $sync = $this->getCalendarSync();

        $client->request('POST', '/settings/apartments/sync/'.$sync->getId().'/edit', [
            'calendar_sync_export' => ['exportReservationTimes' => '1', 'exportPastEntries' => '1'],
        ]);

        self::assertResponseStatusCodeSame(403);
        static::getContainer()->get(ManagerRegistry::class)->getManager()->clear();
        self::assertFalse($this->getCalendarSync()->getExportReservationTimes());
        self::assertFalse($this->getCalendarSync()->getExportPastEntries());
    }

    public function testExportPeriodCanBeSavedAndAppliesToThePublicFeed(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        $admin = $em->getRepository(User::class)->findOneBy(['username' => 'test-admin']);
        self::assertNotNull($admin);
        $client->loginUser($admin, 'main');
        $sync = $this->getCalendarSync();
        self::assertFalse($sync->getExportPastEntries());
        $sync->setIsPublic(true);
        $block = (new RoomBlock())
            ->setAppartment($sync->getApartment())
            ->setStartDate(new \DateTimeImmutable('2000-01-01'))
            ->setEndDate(new \DateTimeImmutable('2000-01-02'))
            ->setReason('Past maintenance');
        $em->persist($block);
        $em->flush();
        $uid = $block->getUuid()->toBase32().'@fewohbee';
        $calendarPath = $this->generateCalendarPath((string) $sync->getUuid());
        $settingsPath = '/settings/apartments/sync/'.$sync->getId().'/edit';

        $client->request('GET', $calendarPath);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($uid, (string) $client->getResponse()->getContent());

        $crawler = $client->request('GET', $settingsPath);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#calendar_sync_export_exportPastEntries option[value="0"][selected]');
        $form = $crawler->filter('form[name="calendar_sync_export"]')->form();
        $form['calendar_sync_export[exportPastEntries]'] = '1';
        $client->submit($form);
        self::assertResponseStatusCodeSame(204);
        static::getContainer()->get(ManagerRegistry::class)->getManager()->clear();
        self::assertTrue($this->getCalendarSync()->getExportPastEntries());

        $client->request('GET', $calendarPath);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($uid, (string) $client->getResponse()->getContent());

        $crawler = $client->request('GET', $settingsPath);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#calendar_sync_export_exportPastEntries option[value="1"][selected]');
        $form = $crawler->filter('form[name="calendar_sync_export"]')->form();
        $form['calendar_sync_export[exportPastEntries]'] = '0';
        $client->submit($form);
        self::assertResponseStatusCodeSame(204);
        static::getContainer()->get(ManagerRegistry::class)->getManager()->clear();
        self::assertFalse($this->getCalendarSync()->getExportPastEntries());

        $client->request('GET', $calendarPath);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($uid, (string) $client->getResponse()->getContent());
    }

    private function getCalendarSync(): CalendarSync
    {
        $container = static::getContainer();
        $doctrine = $container->get(ManagerRegistry::class);
        $em = $doctrine->getManager();
        $sync = $em->getRepository(CalendarSync::class)->findOneBy([]);
        self::assertNotNull($sync, 'Calendar sync must exist in fixtures.');

        return $sync;
    }

    private function flush(CalendarSync $sync): void
    {
        $container = static::getContainer();
        $doctrine = $container->get(ManagerRegistry::class);
        $em = $doctrine->getManager();
        $em->persist($sync);
        $em->flush();
    }

    private function generateCalendarPath(string $uuid): string
    {
        /** @var RouterInterface $router */
        $router = static::getContainer()->get(RouterInterface::class);

        return $router->generate('apartments.get.calendar', ['uuid' => $uuid]);
    }
}
