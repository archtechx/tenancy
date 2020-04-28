<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Middleware;

use Closure;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

/**
 * Prevent access from tenant domains to central routes and vice versa.
 */
class PreventAccessFromTenantDomains
{
    /** @var callable */
    protected $central404;

    public function __construct(callable $central404 = null)
    {
        $this->central404 = $central404 ?? function () {
            return 404;
        };
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        // If the route is universal, always let the request pass.
        if ($this->routeHasMiddleware($request->route(), 'universal')) {
            return $next($request);
        }

        // If the domain is not in exempt domains, it's a tenant domain.
        // Tenant domains can't have routes without tenancy middleware.
        $isExemptDomain = in_array($request->getHost(), config('tenancy.exempt_domains'));
        $isTenantDomain = ! $isExemptDomain;

        $isTenantRoute = $this->routeHasMiddleware($request->route(), 'tenancy');

        if ($isTenantDomain && ! $isTenantRoute) { // accessing web routes from tenant domains
            return redirect(config('tenancy.home_url'));
        }

        if ($isExemptDomain && $isTenantRoute) { // accessing tenant routes on web domains
            return ($this->central404)($request, $next);
        }

        return $next($request);
    }

    public static function routeHasMiddleware(Route $route, $middleware): bool
    {
        if (in_array($middleware, $route->middleware(), true)) {
            return true;
        }

        // Loop one level deep and check if the route's middleware
        // groups have a `tenancy` middleware group inside them
        $middlewareGroups = Router::getMiddlewareGroups();
        foreach ($route->gatherMiddleware() as $inner) {
            if (! $inner instanceof Closure && isset($middlewareGroups[$inner]) && in_array($middleware, $middlewareGroups[$inner], true)) {
                return true;
            }
        }

        return false;
    }
}
