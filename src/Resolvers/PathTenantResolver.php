<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Resolvers;

use Illuminate\Routing\Route;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedByPathException;

class PathTenantResolver extends Contracts\CachedTenantResolver
{
    public static string $tenantParameterName = 'tenant';

    public static bool $shouldCache = false;

    public static int $cacheTTL = 3600; // seconds

    public static string|null $cacheStore = null; // default

    public function resolveWithoutCache(mixed ...$args): Tenant
    {
        /** @var Route $route */
        $route = $args[0];

        if ($id = $route->parameter(static::$tenantParameterName)) {
            $route->forgetParameter(static::$tenantParameterName);

            if ($tenant = tenancy()->find($id)) {
                return $tenant;
            }
        }

        throw new TenantCouldNotBeIdentifiedByPathException($id);
    }

    public function getArgsForTenant(Tenant $tenant): array
    {
        return [
            [$tenant->id],
        ];
    }
}
