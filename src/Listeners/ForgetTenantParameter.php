<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Listeners;

use Illuminate\Routing\Events\RouteMatched;
use Stancl\Tenancy\Enums\RouteMode;
use Stancl\Tenancy\Resolvers\PathTenantResolver;

// todo@earlyIdReview

/**
 * Remove the tenant parameter from the matched route when path identification is used globally.
 *
 * While initializing tenancy, we forget the tenant parameter (in PathTenantResolver),
 * so that the route actions don't have to accept it.
 *
 * With kernel identification, tenancy gets initialized before the route gets matched.
 * The matched route gets the tenant parameter again, so we have to forget the parameter again on RouteMatched.
 *
 * We remove the {tenant} parameter from the matched route when
 * 1) the InitializeTenancyByPath middleware is in the global stack, AND
 * 2) the matched route does not have identification middleware (so that {tenant} isn't forgotten when using route-level identification), AND
 * 3) the route isn't in the central context (so that {tenant} doesn't get accidentally removed from central routes).
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
