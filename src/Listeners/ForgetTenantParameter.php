<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Listeners;

use Illuminate\Routing\Events\RouteMatched;
use Stancl\Tenancy\Enums\RouteMode;
use Stancl\Tenancy\Resolvers\PathTenantResolver;

/**
 * Conditionally removes the tenant parameter from matched routes when using kernel path identification.
 *
 * When path identification middleware is in the global stack,
 * the tenant parameter is initially forgotten during tenancy initialization in PathTenantResolver.
 * However, because kernel identification occurs before route matching, the route still contains
 * the tenant parameter when RouteMatched is fired. This listener removes it to prevent route
 * actions from needing to accept an unwanted tenant parameter.
 *
 * The {tenant} parameter is removed from the matched route only when ALL of these conditions are met:
 * 1) A path identification middleware is in the global middleware stack (kernel identification)
 * 2) The matched route does NOT have its own identification middleware (route-level identification takes precedence)
 * 3) The route is in tenant or universal context (central routes keep their tenant parameter)
 */
class ForgetTenantParameter
{
    public function handle(RouteMatched $event): void
    {
        $pathIdentificationInGlobalStack = tenancy()->globalStackHasMiddleware(config('tenancy.identification.path_identification_middleware'));
        $kernelPathIdentificationUsed = $pathIdentificationInGlobalStack && ! tenancy()->routeHasIdentificationMiddleware($event->route);
        $routeMode = tenancy()->getRouteMode($event->route);
        $routeModeIsTenantOrUniversal = $routeMode === RouteMode::TENANT || ($routeMode === RouteMode::UNIVERSAL && $event->route->hasParameter(PathTenantResolver::tenantParameterName()));

        if ($kernelPathIdentificationUsed && $routeModeIsTenantOrUniversal) {
            $event->route->forgetParameter(PathTenantResolver::tenantParameterName());
        }
    }
}
