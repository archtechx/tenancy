<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Concerns;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route as RouteFacade;
use Stancl\Tenancy\Context;
use Stancl\Tenancy\RouteMode;

// todo1 Name – maybe DealsWithMiddlewareContexts?
trait DealsWithEarlyIdentification
{
    /**
     * Get route's middleware context (tenant or central).
     * The context is determined by the route's middleware.
     *
     * If the route has the 'central' middleware, the context is central.
     * If the route has the 'tenant' middleware, or any tenancy identification middleware, the context is tenant.
     *
     * If the route doesn't have any of the mentioned middleware,
     * the context is determined by the `tenancy.default_route_mode` config.
     */
    public static function getMiddlewareContext(Route $route): RouteMode
    {
        if (static::routeHasMiddleware($route, 'central')) {
            return RouteMode::CENTRAL;
        }

        $defaultRouteMode = config('tenancy.default_route_mode');
        $routeIsUniversal = $defaultRouteMode === RouteMode::UNIVERSAL || static::routeHasMiddleware($route, 'universal');

        // If a route has identification middleware AND the route isn't universal, don't consider the context tenant
        if (static::routeHasMiddleware($route, 'tenant') || static::routeHasIdentificationMiddleware($route) && ! $routeIsUniversal) {
            return RouteMode::TENANT;
        }

        return $defaultRouteMode;
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
        $middlewareGroups = RouteFacade::getMiddlewareGroups();
        $unpackGroupMiddleware = function (array $middleware) use ($middlewareGroups) {
            $innerMiddleware = [];

            foreach ($middleware as $inner) {
                if (! $inner instanceof Closure && isset($middlewareGroups[$inner])) {
                    $innerMiddleware = Arr::wrap($middlewareGroups[$inner]);
                }
            }

            return $innerMiddleware;
        };

        return array_unique(array_merge(
            $routeMiddleware,
            $firstLevelUnpackedGroupMiddleware = $unpackGroupMiddleware($routeMiddleware),
            $thirdLevelUnpackedGroupMiddleware = $unpackGroupMiddleware($firstLevelUnpackedGroupMiddleware),
            $unpackGroupMiddleware($thirdLevelUnpackedGroupMiddleware)
        ));
    }

    /**
     * Check if the passed route has the passed middleware
     * three layers deep – explained in the annotation of getRouteMiddleware().
     */
    public static function routeHasMiddleware(Route $route, string $middleware): bool
    {
        return in_array($middleware, static::getRouteMiddleware($route));
    }

    /**
     * Check if a route has identification middleware.
     */
    public static function routeHasIdentificationMiddleware(Route $route): bool
    {
        foreach (static::getRouteMiddleware($route) as $middleware) {
            if (in_array($middleware, static::middleware())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a route uses domain identification.
     */
    public static function routeHasDomainIdentificationMiddleware(Route $route): bool
    {
        $routeMiddleware = static::getRouteMiddleware($route);

        foreach (config('tenancy.identification.domain_identification_middleware') as $middleware) {
            if (in_array($middleware, $routeMiddleware)) {
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
    public function getRoute(Request $request): Route
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
