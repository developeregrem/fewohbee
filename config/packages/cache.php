<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    // USE_REDIS_CACHE drives a structural choice (which cache adapter class is wired
    // into the compiled container), so it is evaluated at compile time. The actual
    // Redis host / index, however, must be resolved at runtime — otherwise the
    // dockerized images would bake in whatever REDIS_HOST was set during the image
    // build (typically 127.0.0.1) and ignore the runtime override.
    $useRedis = filter_var($_SERVER['USE_REDIS_CACHE'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

    $cache = ['prefix_seed' => 'fewohbee'];

    if ($useRedis) {
        $cache['app'] = 'cache.adapter.redis';
        $cache['system'] = 'cache.adapter.redis';
        $cache['default_redis_provider'] = 'redis://%env(REDIS_HOST)%/%env(REDIS_IDX)%';
    }

    $container->extension('framework', ['cache' => $cache]);
};
