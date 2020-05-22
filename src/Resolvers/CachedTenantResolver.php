<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Resolvers;

use Illuminate\Contracts\Cache\Factory;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Contracts\TenantResolver;

class CachedTenantResolver implements TenantResolver
{
    /** @var CacheManager */
    protected $cache;

    public function __construct(Factory $cache)
    {
        $this->cache = $cache;
    }

    public function resolve(...$args): Tenant
    {
        $resolverClass = $args[0];
        $data = $args[1];
        $ttl = $args[2] ?? null;
        $cacheStore = $args[3] ?? null;

        /** @var TenantResolver $resolver */
        $resolver = app($resolverClass);
        $encodedData = json_encode($data);

        $cache = $this->cache->store($cacheStore);

        if ($cache->has($key = "_tenancy_resolver:$resolverClass:$encodedData")) {
            return $cache->get($key);
        }

        $resolved = $resolver->resolve(...$data);
        $cache->put($key, $resolved, $ttl);

        return $resolved;
    }
}
