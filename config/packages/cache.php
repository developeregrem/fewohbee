<?php

declare(strict_types=1);

use App\Cache\RuntimePrefixedRedisAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    // USE_REDIS_CACHE drives a structural choice (which cache adapter class is wired
    // into the compiled container), so it is evaluated at compile time. The actual
    // Redis host / index, however, must be resolved at runtime — otherwise the
    // dockerized images would bake in whatever REDIS_HOST was set during the image
    // build (typically 127.0.0.1) and ignore the runtime override.
    $useRedis = filter_var($_SERVER['USE_REDIS_CACHE'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

    $cache = ['prefix_seed' => 'fewohbee'];

    if ($useRedis) {
        $services = $container->services();

        // The connection-level prefix is resolved when the service is created,
        // not while the production container is compiled into the image. This
        // is essential for one pre-built image serving multiple K8s tenants.
        $services->set('app.redis_app_cache_connection', \Redis::class)
            ->factory([RedisAdapter::class, 'createConnection'])
            ->args([
                'redis://%env(REDIS_HOST)%:%env(int:REDIS_PORT)%/%env(int:REDIS_IDX)%',
                ['lazy' => true],
            ]);

        $services->set('app.redis_system_cache_connection', \Redis::class)
            ->factory([RedisAdapter::class, 'createConnection'])
            ->args([
                'redis://%env(REDIS_HOST)%:%env(int:REDIS_PORT)%/%env(int:REDIS_SYSTEM_IDX)%',
                ['lazy' => true],
            ]);

        $services->set('app.cache_adapter.redis_app', RuntimePrefixedRedisAdapter::class)
            ->abstract()
            ->args([
                service('app.redis_app_cache_connection'),
                '',
                0,
                service('cache.default_marshaller')->nullOnInvalid(),
                '%env(REDIS_PREFIX)%',
            ])
            ->tag('cache.pool', [
                'provider' => 'app.redis_app_cache_connection',
                'clearer' => 'cache.default_clearer',
                'reset' => 'reset',
            ]);

        // cache.system needs its own provider so REDIS_SYSTEM_IDX is effective.
        $services->set('app.cache_adapter.redis_system', RuntimePrefixedRedisAdapter::class)
            ->abstract()
            ->args([
                service('app.redis_system_cache_connection'),
                '',
                0,
                service('cache.default_marshaller')->nullOnInvalid(),
                '%env(REDIS_PREFIX)%',
            ])
            ->tag('cache.pool', [
                'provider' => 'app.redis_system_cache_connection',
                'clearer' => 'cache.default_clearer',
                'reset' => 'reset',
            ]);

        $cache['app'] = 'app.cache_adapter.redis_app';
        $cache['system'] = 'app.cache_adapter.redis_system';
        $cache['default_redis_provider'] = 'app.redis_app_cache_connection';
    }

    $container->extension('framework', ['cache' => $cache]);
};
