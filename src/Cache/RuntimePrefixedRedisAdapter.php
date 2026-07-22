<?php

declare(strict_types=1);

namespace App\Cache;

use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Marshaller\MarshallerInterface;

/** Adds a runtime tenant prefix without initializing the lazy Redis connection. */
final class RuntimePrefixedRedisAdapter extends RedisAdapter
{
    public function __construct(
        \Redis|\RedisArray|\RedisCluster|\Predis\ClientInterface|\Relay\Relay|\Relay\Cluster $redis,
        string $namespace = '',
        int $defaultLifetime = 0,
        ?MarshallerInterface $marshaller = null,
        string $runtimePrefix = '',
    ) {
        parent::__construct($redis, $runtimePrefix.$namespace, $defaultLifetime, $marshaller);
    }
}
