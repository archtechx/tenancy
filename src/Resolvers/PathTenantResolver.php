<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Resolvers;

use Illuminate\Routing\Route;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedByPathException;

class PathTenantResolver extends Contracts\CachedTenantResolver
{
    public static $tenantParameterName = 'tenant';

    /** @var bool */
    public static $shouldCache = false;

    /** @var int */
    public static $cacheTTL = 3600; // seconds

    /** @var string|null */
    public static $cacheStore = null; // default

    /** @var string */
    public static $resolvingColumn = 'id'; // default

    public function resolveWithoutCache(...$args): Tenant
    {
        /** @var Route $route */
        $route = $args[0];

        $resolvingColumn = static::$resolvingColumn;

        if ($value = $route->parameter(static::$tenantParameterName)) {
            $route->forgetParameter(static::$tenantParameterName);

            if ($tenant = tenancy()->where($resolvingColumn, $value)->first()) {
                return $tenant;
            }
        }

        throw new TenantCouldNotBeIdentifiedByPathException($resolvingColumn, $value);
    }

    public function resolved(Tenant $tenant, ...$args): void
    {
        /** @var Route $route */
        $route = $args[0];

        $route->forgetParameter(static::$tenantParameterName);
    }

    public function getArgsForTenant(Tenant $tenant): array
    {
        return [
            [$tenant->id],
        ];
    }
}
