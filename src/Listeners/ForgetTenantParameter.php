<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Listeners;

use Illuminate\Routing\Events\RouteMatched;
use Stancl\Tenancy\PathIdentificationManager;
use Stancl\Tenancy\RouteMode;

/**
 * Remove the tenant parameter from the matched route when path identification is used globally.
 *
 * The tenant parameter gets forgotten using PathTenantResolver so that the route actions don't have to accept it.
 * Then, tenancy gets initialized, and URL::defaults() is used to give the tenant parameter to the next matched route.
 * But with kernel identification, the route gets matched AFTER the point when URL::defaults() is used,
 * and because of that, the matched route gets the tenant parameter again, so we forget the parameter again on RouteMatched.
 *
 * We remove the {tenant} parameter from the hydrated route when
 * 1) the InitializeTenancyByPath middleware is in the global stack, AND
 * 2) the matched route does not have identification middleware (so that {tenant} isn't forgotten when using route-level identification), AND
 * 3) the route has tenant middleware context (so that {tenant} doesn't get accidentally removed from central routes).
 */
class ForgetTenantParameter
{
    public function handle(RouteMatched $event): void
    {
        if (
            PathIdentificationManager::pathIdentificationInGlobalStack() &&
            ! tenancy()->routeHasIdentificationMiddleware($event->route) &&
            tenancy()->getMiddlewareContext($event->route) === RouteMode::TENANT
        ) {
            $event->route->forgetParameter(PathIdentificationManager::getTenantParameterName());
        }
    }
}
