<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Resolvers\Contracts;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException;
use Stancl\Tenancy\Contracts\TenantResolver;

abstract class CachedTenantResolver implements TenantResolver
{
    protected Repository $cache;

    public function __construct(Application $app)
    {
        // globalCache should generally not be injected, however in this case
        // the class is always created from scratch when calling invalidateCache()
        // meaning the global cache stores are also resolved from scratch.
        $this->cache = $app->make('globalCache')->store(static::cacheStore());
    }

    /**
     * Resolve a tenant using some value passed from the middleware.
     *
     * @throws TenantCouldNotBeIdentifiedException
     */
    public function resolve(mixed ...$args): Tenant
    {
        if (! static::shouldCache()) {
            $tenant = $this->resolveWithoutCache(...$args);
            $this->resolved($tenant, ...$args);

            return $tenant;
        }

        $key = $this->formatCacheKey(...$args);

        if ($tenant = $this->cache->get($key)) {
            $this->resolved($tenant, ...$args);

            return $tenant;
        }

        $tenant = $this->resolveWithoutCache(...$args);
        $this->cache->put($key, $tenant, static::cacheTTL());
        $this->resolved($tenant, ...$args);

        return $tenant;
    }

    /**
     * Invalidate this resolver's cache for a tenant.
     */
    public function invalidateCache(Tenant&Model $tenant): void
    {
        if (! static::shouldCache()) {
            return;
        }

        foreach ($this->getPossibleCacheKeys($tenant) as $key) {
            $this->cache->forget($key);
        }
    }

    public function formatCacheKey(mixed ...$args): string
    {
        return '_tenancy_resolver:' . static::class . ':' . json_encode($args);
    }

    /**
     * Resolve a tenant using $args passed from middleware, without using cache.
     *
     * @throws TenantCouldNotBeIdentifiedException
     */
    abstract public function resolveWithoutCache(mixed ...$args): Tenant;

    /**
     * Called after a tenant has been resolved from cache or without cache.
     *
     * Used for side effects like removing the tenant parameter from the request route.
     */
    public function resolved(Tenant $tenant, mixed ...$args): void {}

    abstract public function getPossibleCacheKeys(Tenant&Model $tenant): array;

    public static function shouldCache(): bool
    {
        return (config('tenancy.identification.resolvers.' . static::class . '.cache') ?? false) && static::cacheTTL() > 0;
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
