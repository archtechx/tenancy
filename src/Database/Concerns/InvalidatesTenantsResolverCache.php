<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Resolvers\Contracts\CachedTenantResolver;
use Stancl\Tenancy\Tenancy;

/**
 * Meant to be used on models that belong to tenants.
 */
trait InvalidatesTenantsResolverCache
{
    public static function bootInvalidatesTenantsResolverCache(): void
    {
        static::saved(function (Model $model) {
            foreach (Tenancy::cachedResolvers() as $resolver) {
                /** @var CachedTenantResolver $resolver */
                $resolver = app($resolver);

                $resolver->invalidateCache($model->tenant);
            }
        });
    }
}
