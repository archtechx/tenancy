<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Concerns;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Stancl\Tenancy\Context;
use Stancl\Tenancy\Exceptions\MiddlewareNotUsableWithUniversalRoutesException;
use Stancl\Tenancy\Middleware\IdentificationMiddleware;
use Stancl\Tenancy\Middleware\PreventAccessFromUnwantedDomains;
use Stancl\Tenancy\RouteMode;

/**
 * This trait provides methods that check if a middleware's execution should be skipped.
 * This is primarily used to ensure that kernel identification is skipped when the route has
 * identification middleware (= route-level identification is prioritized over kernel identification).
 *
 * When using kernel domain identification, you'll most likely also use the PreventAccessFromUnwantedDomains middleware in the global stack.
 * PreventAccessFromUnwantedDomains isn't an identification middleware, but we have to skip it too,
 * e.g. when using the MW in the global stack and at the same time, we're using route-level identification.
 *
 * You need to use this trait directly on each identification middleware — it can't be used in a base class that's extended.
 * The inGlobalStack() method checks for the specific middleware the trait is used on (`static::class`).
 */
trait UsableWithEarlyIdentification
{
    /**
     * Skip middleware if the route is universal and uses path identification or if the route is universal and the context should be central.
     * Universal routes using path identification should get re-registered using ReregisterRoutesAsTenant.
     *
     * @see \Stancl\Tenancy\Actions\CloneRoutesAsTenant
     */
    protected function shouldBeSkipped(Route $route): bool
    {
        $routeMiddleware = tenancy()->getRouteMiddleware($route);
        $universalFlagUsed = in_array('universal', $routeMiddleware);
        $defaultToUniversalRoutes = config('tenancy.default_route_mode') === RouteMode::UNIVERSAL;

        // Route is universal only if it doesn't have the central/tenant flag
        $routeIsUniversal = ($universalFlagUsed || $defaultToUniversalRoutes) &&
            ! (in_array('central', $routeMiddleware) || in_array('tenant', $routeMiddleware));

        if ($routeIsUniversal && $this instanceof IdentificationMiddleware) {
            /** @phpstan-ignore-next-line */
            throw_unless($this instanceof UsableWithUniversalRoutes, MiddlewareNotUsableWithUniversalRoutesException::class);

            return $this->determineUniversalRouteContextFromRequest(request()) === Context::CENTRAL;
        }

        // If the middleware is not in the global stack (= it's used directly on the route)
        // And the route isn't universal, don't skip it
        if (! static::inGlobalStack()) {
            return false;
        }

        // Now that we're sure the MW isn't used in the global MW stack, we determine whether to skip it
        if ($this instanceof PreventAccessFromUnwantedDomains) {
            // Skip access prevention if the route directly uses a non-domain identification middleware
            return tenancy()->routeHasIdentificationMiddleware($route) && ! tenancy()->routeHasDomainIdentificationMiddleware($route);
        }

        return $this->shouldIdentificationMiddlewareBeSkipped($route);
    }

    protected function determineUniversalRouteContextFromRequest(Request $request): Context
    {
        $route = tenancy()->getRoute($request);

        // Check if this is the identification middleware the route should be using
        // Route-level identification middleware is prioritized
        $middlewareUsed = tenancy()->routeHasMiddleware($route, static::class) || ! tenancy()->routeHasIdentificationMiddleware($route) && static::inGlobalStack();

        /** @var UsableWithUniversalRoutes $this */
        return $middlewareUsed && $this->requestHasTenant($request) ? Context::TENANT : Context::CENTRAL;
    }

    protected function shouldIdentificationMiddlewareBeSkipped(Route $route): bool
    {
        if (! static::inGlobalStack()) {
            return false;
        }

        $request = app(Request::class);

        if (! $request->attributes->get('_tenancy_kernel_identification_skipped')) {
            if (
                // Skip identification if the current route is central
                // The route is central if defaulting is set to central and the route isn't flagged as tenant or it doesn't have identification middleware
                tenancy()->getMiddlewareContext($route) === RouteMode::CENTRAL
                // Don't skip identification if the central route is considered universal
                && (config('tenancy.default_route_mode') !== RouteMode::UNIVERSAL || ! tenancy()->routeHasMiddleware($route, 'universal'))
            ) {
                return true;
            }

            // Skip kernel identification if the route uses route-level identification
            if (tenancy()->routeHasIdentificationMiddleware($route)) {
                // Remember that it was attempted to identify a tenant using kernel identification
                // By making the $kernelIdentificationSkipped property of the current Tenancy instance true
                // So that the next identification middleware gets executed (= route-level identification MW doesn't get skipped)
                $request->attributes->set('_tenancy_kernel_identification_skipped', true);

                // Skip kernel identification so that route-level identification middleware can get used
                return true;
            }
        }

        return false;
    }

    public static function inGlobalStack(): bool
    {
        return app(Kernel::class)->hasMiddleware(static::class);
    }
}
