<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Closure;
use Illuminate\Routing\Route;
use Stancl\Tenancy\Resolvers\PathTenantResolver;

class PathIdentificationManager
{
    public static Closure|null $tenantParameterName = null;
    public static Closure|null $tenantRouteNamePrefix = null;

    /**
     * Get the tenant parameter name using the static property.
     * Default to PathTenantResolver::tenantParameterName().
     */
    public static function getTenantParameterName(): string
    {
        return static::$tenantParameterName ? (static::$tenantParameterName)() : PathTenantResolver::tenantParameterName();
    }

    /**
     * Get the tenant route name prefix using the static property.
     * Default to PathTenantResolver::tenantRouteNamePrefix().
     */
    public static function getTenantRouteNamePrefix(): string
    {
        return static::$tenantRouteNamePrefix ? (static::$tenantRouteNamePrefix)() : PathTenantResolver::tenantRouteNamePrefix();
    }

    public static function pathIdentificationOnRoute(Route $route): bool
    {
        return static::checkPathIdentificationMiddleware(fn ($middleware) => tenancy()->routeHasMiddleware($route, $middleware));
    }

    public static function pathIdentificationInGlobalStack(): bool
    {
        return static::checkPathIdentificationMiddleware(fn ($middleware) => $middleware::inGlobalStack());
    }

    protected static function checkPathIdentificationMiddleware(Closure $closure): bool
    {
        foreach (static::getPathIdentificationMiddleware() as $middleware) {
            if ($closure($middleware)) {
                return true;
            }
        }

        return false;
    }

    protected static function getPathIdentificationMiddleware(): array
    {
        return config('tenancy.identification.path_identification_middleware');
    }
}
