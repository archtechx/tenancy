<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Features;

use Closure;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Stancl\Tenancy\Contracts\Feature;
use Stancl\Tenancy\Middleware;
use Stancl\Tenancy\Tenancy;

class UniversalRoutes implements Feature
{
    public static string $middlewareGroup = 'universal';

    // todo docblock
    public static array $identificationMiddlewares = [
        Middleware\InitializeTenancyByDomain::class,
        Middleware\InitializeTenancyBySubdomain::class,
    ];

    public function bootstrap(Tenancy $tenancy): void
    {
        foreach (static::$identificationMiddlewares as $middleware) {
            $originalOnFail = $middleware::$onFail;

            $middleware::$onFail = function ($exception, $request, $next) use ($originalOnFail) {
                if (static::routeHasMiddleware($request->route(), static::$middlewareGroup)) {
                    return $next($request);
                }

                if ($originalOnFail) {
                    return $originalOnFail($exception, $request, $next);
                }

                throw $exception;
            };
        }
    }

    public static function routeHasMiddleware(Route $route, string $middleware): bool
    {
        if (in_array($middleware, $route->middleware(), true)) {
            return true;
        }

        // Loop one level deep and check if the route's middleware
        // groups have the searched middleware group inside them
        $middlewareGroups = Router::getMiddlewareGroups();
        foreach ($route->gatherMiddleware() as $inner) {
            if (! $inner instanceof Closure && isset($middlewareGroups[$inner]) && in_array($middleware, $middlewareGroups[$inner], true)) {
                return true;
            }
        }

        return false;
    }
}
