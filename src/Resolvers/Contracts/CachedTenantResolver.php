<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Resolvers\Contracts;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Contracts\TenantResolver;

abstract class CachedTenantResolver implements TenantResolver
{
    /** @var Repository */
    protected $cache;

    public function __construct(Factory $cache)
    {
        $this->cache = $cache->store(static::cacheStore());
    }

    public function resolve(mixed ...$args): Tenant
    {
        if (! static::shouldCache()) {
            return $this->resolveWithoutCache(...$args);
        }

        $key = $this->getCacheKey(...$args);

        if ($tenant = $this->cache->get($key)) {
            $this->resolved($tenant, ...$args);

            return $tenant;
        }

        $tenant = $this->resolveWithoutCache(...$args);
        $this->cache->put($key, $tenant, static::cacheTTL());

        return $tenant;
    }

    public function invalidateCache(Tenant $tenant): void
    {
        if (! static::shouldCache()) {
            return;
        }

        foreach ($this->getArgsForTenant($tenant) as $args) {
            $this->cache->forget($this->getCacheKey(...$args));
        }
    }

    public function getCacheKey(mixed ...$args): string
    {
        return '_tenancy_resolver:' . static::class . ':' . json_encode($args);
    }

    abstract public function resolveWithoutCache(mixed ...$args): Tenant;

    public function resolved(Tenant $tenant, mixed ...$args): void
    {
    }

    /**
     * Get all possible argument combinations for resolve() which can be used for caching the tenant.
     *
     * This is used during tenant cache invalidation.
     *
     * @return array[]
     */
    abstract public function getArgsForTenant(Tenant $tenant): array;

    public static function shouldCache(): bool
    {
        return config('tenancy.identification.resolvers.' . static::class . '.cache') ?? false;
    }

    public static function cacheTTL(): int
    {
        return config('tenancy.identification.resolvers.' . static::class . '.cache_ttl') ?? 3600;
    }

    public static function cacheStore(): string|null
    {
        return config('tenancy.identification.resolvers.' . static::class . '.cache_store');
    }
}
