<?php

declare(strict_types=1);

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class HealthController extends AbstractController
{
    public function __construct(
        #[Autowire('%env(bool:default::USE_REDIS_CACHE)%')]
        private readonly bool $useRedisCache,
        #[Autowire('%env(default::REDIS_HOST)%')]
        private readonly string $redisHost,
        #[Autowire('%env(int:default::REDIS_IDX)%')]
        private readonly int $redisIdx,
        #[Autowire('%env(default::HEALTH_TOKEN)%')]
        private readonly ?string $healthToken,
    ) {
    }

    #[Route('/health/live', name: 'health.live', methods: ['GET'])]
    public function live(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }

    #[Route('/health/ready', name: 'health.ready', methods: ['GET'])]
    public function ready(Request $request, Connection $db): JsonResponse
    {
        if ($this->healthToken !== null && $request->headers->get('X-Health-Token') !== $this->healthToken) {
            return new JsonResponse(['status' => 'unauthorized'], 401);
        }

        $checks = ['db' => $this->checkDb($db) ? 'ok' : 'fail'];

        if ($this->isRedisInUse()) {
            $checks['redis'] = $this->checkRedis() ? 'ok' : 'fail';
        }

        $ok = !in_array('fail', $checks, true);

        return new JsonResponse(
            ['status' => $ok ? 'ok' : 'fail', 'checks' => $checks],
            $ok ? 200 : 503,
        );
    }

    private function checkDb(Connection $db): bool
    {
        try {
            $db->executeQuery('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function isRedisInUse(): bool
    {
        return $this->useRedisCache && $this->redisHost !== '' && \extension_loaded('redis');
    }

    private function checkRedis(): bool
    {
        try {
            $redis = new \Redis();
            $redis->connect($this->redisHost, 6379, 1.0);
            $redis->select($this->redisIdx);
            $result = $redis->ping();
            $redis->close();

            return $result === true || $result === '+PONG' || $result === 'PONG';
        } catch (\Throwable) {
            return false;
        }
    }
}
