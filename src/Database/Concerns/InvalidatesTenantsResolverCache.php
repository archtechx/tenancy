<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Resolvers;
use Stancl\Tenancy\Resolvers\Contracts\CachedTenantResolver;

/**
 * Meant to be used on models that belong to tenants.
 */
trait InvalidatesTenantsResolverCache
{
    public static $resolvers = [
        Resolvers\DomainTenantResolver::class,
        Resolvers\PathTenantResolver::class,
        Resolvers\RequestDataTenantResolver::class,
    ];

    public static function bootInvalidatesTenantsResolverCache()
    {
        static::saved(fn(Model $model) => static::invalidateTenantCache($model));
        static::deleted(fn(Model $model) => static::invalidateTenantCache($model));
    }

    private static function invalidateTenantCache(Model $model): void
    {
        foreach (static::$resolvers as $resolver) {
            /** @var CachedTenantResolver $resolver */
            $resolver = app($resolver);

            $resolver->invalidateCache($model->tenant);
        }
    }
}
