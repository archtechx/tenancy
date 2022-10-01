<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Resolvers;
use Stancl\Tenancy\Resolvers\Contracts\CachedTenantResolver;

trait InvalidatesResolverCache
{
    /** @var array<class-string<CachedTenantResolver>> */
    public static $resolvers = [ // todo@deprecated, move this to a config key? related to a todo in InvalidatesTenantsResolverCache
        Resolvers\DomainTenantResolver::class,
        Resolvers\PathTenantResolver::class,
        Resolvers\RequestDataTenantResolver::class,
    ];

    public static function bootInvalidatesResolverCache(): void
    {
        static::saved(function (Tenant $tenant) {
            foreach (static::$resolvers as $resolver) {
                /** @var CachedTenantResolver $resolver */
                $resolver = app($resolver);

                $resolver->invalidateCache($tenant);
            }
        });
    }
}
