<?php

namespace Stancl\Tenancy\Features;

use Closure;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Stancl\Tenancy\Contracts\Feature;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Tenancy;

class UniversalRoutes implements Feature
{
    public static $identificationMiddlewares = [
        InitializeTenancyByDomain::class,
    ];

    public function bootstrap(Tenancy $tenancy): void
    {
        foreach (static::$identificationMiddlewares as $middleware) {
            $middleware::$onFail = function ($exception, $request, $next) {
                if (static::routeHasMiddleware($request->route(), 'universal')) {
                    return $next($request);
                }

                throw $exception;
            };
        }
    }

    public static function routeHasMiddleware(Route $route, $middleware): bool
    {
        if (in_array($middleware, $route->middleware(), true)) {
            return true;
        }

        // Loop one level deep and check if the route's middleware
        // groups have the searhced middleware group inside them
        $middlewareGroups = Router::getMiddlewareGroups();
        foreach ($route->gatherMiddleware() as $inner) {
            if (!$inner instanceof Closure && isset($middlewareGroups[$inner]) && in_array($middleware, $middlewareGroups[$inner], true)) {
                return true;
            }
        }

        return false;
    }
}
