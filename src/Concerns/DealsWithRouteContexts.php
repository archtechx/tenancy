<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Concerns;

use Closure;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route as RouteFacade;
use Stancl\Tenancy\Enums\RouteMode;

/**
 * @mixin \Stancl\Tenancy\Tenancy
 * @internal The public methods in this trait should not be understood to be a public stable API.
 */
trait DealsWithRouteContexts
{
    /**
     * Get the middleware context of a route (tenant, central, or universal).
     *
     * If the route has the 'universal' middleware, the context is universal,
     * and the route is accessible from both contexts.
     *
     * The universal flag has the highest priority.
     *
     * If you want a universal route to be accessible from the tenant context,
     * you still have to provide an identification middleware either using
     * route-level middleware or in the global middleware stack.
     *
     * If the 'tenant' group has identification middleware, you can use it in
     * combination with the 'universal' flag, the route will still be universal.
     *
     * If the route has the 'tenant' middleware, or any tenancy identification
     * middleware, the context is tenant (assuming the route doesn't also have
     * the 'universal' flag).
     *
     * If the route has the 'central' middleware, the context is central.
     *
     * If the route doesn't have any of the mentioned flags/middleware,
     * the context is determined by the `tenancy.default_route_mode` config.
     *
     * If the default route mode is tenant, all unflagged routes will be tenant by default,
     * but they will still have to have an identification middleware (route-level
     * or global) to be accessible. Same applies for universal default route mode.
     */
    public static function getRouteMode(Route $route): RouteMode
    {
        // If the route is universal, you have to determine its actual context using
        // the identification middleware's determineUniversalRouteContextFromRequest
        if (static::routeIsUniversal($route)) {
            return RouteMode::UNIVERSAL;
        }

        if (static::routeHasMiddleware($route, 'central')) {
            return RouteMode::CENTRAL;
        }

        // If the route is flagged as tenant or it has identification middleware, consider it tenant
        if (static::routeHasMiddleware($route, 'tenant') || static::routeHasIdentificationMiddleware($route)) {
            return RouteMode::TENANT;
        }

        return config('tenancy.default_route_mode');
    }

    public static function routeIsUniversal(Route $route): bool
    {
        $routeFlaggedAsUniversal = static::routeHasMiddleware($route, 'universal');
        $universalFlagUsedInGlobalStack = app(Kernel::class)->hasMiddleware('universal');
        $defaultRouteModeIsUniversal = config('tenancy.default_route_mode') === RouteMode::UNIVERSAL;

        return $routeFlaggedAsUniversal || $universalFlagUsedInGlobalStack || $defaultRouteModeIsUniversal;
    }

    /**
     * Get middleware of the passed route (without controller middleware and middleware from the global stack).
     *
     * First, get the surface-level route middleware (`$route->middleware()`).
     * The surface-level middleware could contain middleware groups,
     * and to accurately get all the specific middleware, we need to unpack them.
     * The unpacked middleware groups could also have middleware groups inside them,
     * so we further unpack these, three times.
     *
     * For example, a route has a 'surface' middleware group.
     * The 'surface' group has a 'first-level' group, and that group has a 'second-level' group (three middleware group layers).
     * The 'second-level' group has a specific middleware (e.g. SomeMiddleware).
     * Using the getRouteMiddleware method on that route will get you all the middleware the route has, including SomeMiddleware.
     *
     * Note that the unpacking doesn't go further than three layers – if 'second-level' had 'third-level' that would have ThirdLevelMiddleware,
     * the middleware returned by this method won't include ThirdLevelMiddleware because the 'third-level' group won't get unpacked.
     */
    public static function getRouteMiddleware(Route $route): array
    {
        $routeMiddleware = $route->middleware();
        $controllerClass = $route->getControllerClass();

        if ($controllerClass && is_a($controllerClass, HasMiddleware::class, true)) {
            $routeMiddleware = array_merge($routeMiddleware, $route->controllerMiddleware());
        }

        $middlewareGroups = RouteFacade::getMiddlewareGroups();
        $unpackGroupMiddleware = function (array $middleware) use ($middlewareGroups) {
            $innerMiddleware = [];

            foreach ($middleware as $inner) {
                if (! $inner instanceof Closure && isset($middlewareGroups[$inner])) {
                    $innerMiddleware = array_merge($innerMiddleware, Arr::wrap($middlewareGroups[$inner]));
                }
            }

            return $innerMiddleware;
        };

        return array_unique(array_merge(
            $routeMiddleware,
            $firstLevelUnpackedGroupMiddleware = $unpackGroupMiddleware($routeMiddleware),
            $secondLevelUnpackedGroupMiddleware = $unpackGroupMiddleware($firstLevelUnpackedGroupMiddleware),
            $unpackGroupMiddleware($secondLevelUnpackedGroupMiddleware)
        ));
    }

    /**
     * Checks whether any of the passed middleware are present in the route's middleware stack.
     */
    public static function routeHasMiddleware(Route $route, string|array $middlewares): bool
    {
        $routeMiddleware = static::getRouteMiddleware($route);

        foreach (Arr::wrap($middlewares) as $middleware) {
            if (in_array($middleware, $routeMiddleware)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a route has identification middleware.
     */
    public static function routeHasIdentificationMiddleware(Route $route): bool
    {
        return static::routeHasMiddleware($route, static::middleware());
    }

    /**
     * Check if route uses kernel identification (identification middleware is in the global stack and the route doesn't have route-level identification middleware).
     */
    public static function routeUsesKernelIdentification(Route $route): bool
    {
        return ! static::routeHasIdentificationMiddleware($route) &&
            tenancy()->globalStackHasMiddleware(static::middleware());
    }

    /**
     * Checks whether any of the passed middleware are present in the global middleware stack.
     *
     * @param class-string|array $identificationMiddleware
     */
    public static function globalStackHasMiddleware(string|array $identificationMiddleware = []): bool
    {
        /** @var Kernel $kernel */
        $kernel = app(Kernel::class);

        foreach (Arr::wrap($identificationMiddleware) as $middleware) {
            if ($kernel->hasMiddleware($middleware)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Obtain route from the passed request.
     * If the route isn't directly available on the request,
     * find the route that matches the passed request.
     */
    public static function getRoute(Request $request): Route
    {
        /** @var ?Route $route */
        $route = $request->route();

        if (! $route) {
            /** @var Router $router */
            $router = app(Router::class);
            $route = $router->getRoutes()->match($request);
        }

        return $route;
    }
}
