<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Resolvers;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Contracts\TenantResolver;

class CachedTenantResolver implements TenantResolver
{
    public function __construct(
        private readonly TenantResolver $tenantResolver,
        private readonly CacheRepository $cache,
        private readonly string $prefix,
        private readonly int $ttl = 3600,
    ) {
    }

    public function resolve(mixed ...$args): Tenant
    {
        return $this->cache->remember(
            key: $this->getCacheKey(...$args),
            ttl: $this->ttl,
            callback: fn() => $this->tenantResolver->resolve(...$args)
        );
    }

    public function getCacheKey(mixed ...$args): string
    {
        return sprintf('%s:%s', $this->prefix, json_encode($args));
    }
}
