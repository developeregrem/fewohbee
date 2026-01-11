<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\CalendarSync;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
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
