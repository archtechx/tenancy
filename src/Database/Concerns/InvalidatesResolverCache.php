<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Resolvers\Contracts\CachedTenantResolver;
use Stancl\Tenancy\Tenancy;

trait InvalidatesResolverCache
{
    public static function bootInvalidatesResolverCache(): void
    {
        static::saved(function (Tenant $tenant) {
            foreach (Tenancy::cachedResolvers() as $resolver) {
                /** @var CachedTenantResolver $resolver */
                $resolver = app($resolver);

                $resolver->invalidateCache($tenant);
            }
        });
    }
}
