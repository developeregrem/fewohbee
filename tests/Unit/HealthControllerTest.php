<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Controller\HealthController;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;

final class HealthControllerTest extends TestCase
{
    public function testLiveReturnsOkWithoutAnyDependencies(): void
    {
        $controller = new HealthController(false, '', 1, null);

        $response = $controller->live();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"status":"ok"}', $response->getContent());
    }

    public function testReadyReturnsOkWhenDbReachableAndRedisNotInUse(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('executeQuery')->with('SELECT 1');

        $controller = $this->controllerWithContainer(useRedisCache: false);
        $response = $controller->ready(new Request(), $db);

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString(
            '{"status":"ok","checks":{"db":"ok"}}',
            (string) $response->getContent(),
        );
    }

    public function testReadyReturns503WhenDbThrows(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('executeQuery')->willThrowException(new \RuntimeException('boom'));

        $controller = $this->controllerWithContainer(useRedisCache: false);
        $response = $controller->ready(new Request(), $db);

        self::assertSame(503, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString(
            '{"status":"fail","checks":{"db":"fail"}}',
            (string) $response->getContent(),
        );
    }

    public function testReadyRequiresTokenWhenConfigured(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('executeQuery');

        $controller = $this->controllerWithContainer(healthToken: 'secret');
        $response = $controller->ready(new Request(), $db);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testReadyAcceptsValidToken(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('executeQuery');

        $controller = $this->controllerWithContainer(healthToken: 'secret');
        $request = new Request();
        $request->headers->set('X-Health-Token', 'secret');

        $response = $controller->ready($request, $db);

        self::assertSame(200, $response->getStatusCode());
    }

    private function controllerWithContainer(
        bool $useRedisCache = false,
        string $redisHost = '',
        int $redisIdx = 1,
        ?string $healthToken = null,
    ): HealthController {
        $controller = new HealthController($useRedisCache, $redisHost, $redisIdx, $healthToken);
        $controller->setContainer(new Container());

        return $controller;
    }
}
