<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $useRedis = filter_var($_SERVER['USE_REDIS_CACHE'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

    $cache = ['prefix_seed' => 'fewohbee'];

    if ($useRedis) {
        $redisHost = $_SERVER['REDIS_HOST'] ?? 'redis';
        $redisIdx = $_SERVER['REDIS_IDX'] ?? '1';
        $cache['app'] = 'cache.adapter.redis';
        $cache['system'] = 'cache.adapter.redis';
        $cache['default_redis_provider'] = sprintf('redis://%s/%s', $redisHost, $redisIdx);
    }

    $container->extension('framework', ['cache' => $cache]);
};
