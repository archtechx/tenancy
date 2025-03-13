<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Resolvers;
use Stancl\Tenancy\Resolvers\Contracts\CachedTenantResolver;

trait InvalidatesResolverCache
{
    public static $resolvers = [
        Resolvers\DomainTenantResolver::class,
        Resolvers\PathTenantResolver::class,
        Resolvers\RequestDataTenantResolver::class,
    ];

    public static function bootInvalidatesResolverCache()
    {
        static::saved(fn(Tenant $tenant) => static::invalidateTenantCache($tenant));
        static::deleting(fn(Tenant $tenant) => static::invalidateTenantCache($tenant));
    }

    private static function invalidateTenantCache(Tenant $tenant): void
    {
        foreach (static::$resolvers as $resolver) {
            /** @var CachedTenantResolver $resolver */
            $resolver = app($resolver);

            $resolver->invalidateCache($tenant);
        }
    }
}
