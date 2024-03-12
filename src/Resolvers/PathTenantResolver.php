<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Resolvers;

use Illuminate\Routing\Route;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedByPathException;
use Stancl\Tenancy\PathIdentificationManager;

class PathTenantResolver extends Contracts\CachedTenantResolver
{
    public function resolveWithoutCache(mixed ...$args): Tenant
    {
        /** @var Route $route */
        $route = $args[0];

        /** @var string $id */
        $id = $route->parameter(static::tenantParameterName());

        if ($id) {
            // Forget the tenant parameter so that we don't have to accept it in route action methods
            $route->forgetParameter(static::tenantParameterName());

            if ($tenant = tenancy()->find($id)) {
                return $tenant;
            }
        }

        throw new TenantCouldNotBeIdentifiedByPathException($id);
    }

    public function getArgsForTenant(Tenant $tenant): array
    {
        return [
            [$tenant->getTenantKey()],
        ];
    }

    public function resolved(Tenant $tenant, mixed ...$args): void
    {
        /** @var Route $route */
        $route = $args[0];

        $route->forgetParameter(PathIdentificationManager::getTenantParameterName());
    }

    public function getCacheKey(mixed ...$args): string
    {
        // todo@samuel: fix the coupling here. when this is called from the cachedresolver, $args are the tenant key. when it's called from within this class, $args are a Route instance
        // the logic shouldn't have to be coupled to where it's being called from

        // todo@samuel also make the tenant column configurable

        // $args[0] can be either a Route instance with the tenant key as a parameter
        // Or the tenant key
        $args = [$args[0] instanceof Route ? $args[0]->parameter(static::tenantParameterName()) : $args[0]];

        return '_tenancy_resolver:' . static::class . ':' . json_encode($args);
    }

    public static function tenantParameterName(): string
    {
        return config('tenancy.identification.resolvers.' . static::class . '.tenant_parameter_name') ?? 'tenant';
    }

    public static function tenantRouteNamePrefix(): string
    {
        return config('tenancy.identification.resolvers.' . static::class . '.tenant_route_name_prefix') ?? static::tenantParameterName() . '.';
    }
}
