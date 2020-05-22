<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Resolvers;

use Illuminate\Cache\Repository;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Contracts\TenantResolver;

class CachedTenantResolver implements TenantResolver
{
    /** @var Repository */
    protected $cache;

    public function __construct(Repository $cache)
    {
        $this->cache = $cache;
    }

    public function resolve(...$args): Tenant
    {
        $resolverClass = $args[0];
        $data = $args[1];

        /** @var TenantResolver $resolver */
        $resolver = app($resolverClass);
        $encodedData = json_encode($data);

        if ($this->cache->has($key = "_tenancy_resolver:$resolverClass:$encodedData")) {
            return $this->cache->get($key);
        }

        $this->cache->put($key,
            $resolved = $resolver->resolve(...$data)
        );

        return $resolved;
    }
}
