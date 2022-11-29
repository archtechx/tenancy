<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

// todo come up with a better name
class PreventAccessFromUnwantedDomains
{
    /**
     * Set this property if you want to customize the on-fail behavior.
     */
    public static ?Closure $abortRequest;

    /** @return \Illuminate\Http\Response|mixed */
    public function handle(Request $request, Closure $next): mixed
    {
        if ($this->routeHasMiddleware($request->route(), 'universal')) {
            return $next($request);
        }

        if (in_array($request->getHost(), config('tenancy.central_domains'), true)) {
            $abortRequest = static::$abortRequest ?? function () {
                abort(404);
            };

            return $abortRequest($request, $next);
        }

        return $next($request);
    }

    protected function routeHasMiddleware(Route $route, string $middleware): bool
    {
        /** @var array $routeMiddleware */
        $routeMiddleware = $route->middleware();

        if (in_array($middleware, $routeMiddleware, true)) {
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
