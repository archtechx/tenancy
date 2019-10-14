<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Middleware;

use Closure;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

/**
 * Prevent access to non-tenant routes from domains that are not exempt from tenancy.
 * = allow access to central routes only from routes listed in tenancy.exempt_routes.
 */
class PreventAccessFromTenantDomains
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        // If the domain is not in exempt domains, it's a tenant domain.
        // Tenant domains can't have routes without tenancy middleware.
        $isExemptDomain = in_array($request->getHost(), config('tenancy.exempt_domains'));
        $isTenantDomain = ! $isExemptDomain;

        $isTenantRoute = $this->isTenantRoute($request->route());

        if ($isTenantDomain && ! $isTenantRoute) { // accessing web routes from tenant domains
            return redirect(config('tenancy.home_url'));
        }

        if ($isExemptDomain && $isTenantRoute) { // accessing tenant routes on web domains
            abort(404);
        }

        return $next($request);
    }

    public function isTenantRoute(Route $route): bool
    {
        if (in_array('tenancy', $route->middleware(), true)) {
            return true;
        }

        // Loop one level deep and check if the route's middleware
        // groups have a `tenancy` middleware grouú inside them
        $middlewareGroups = Router::getMiddlewareGroups();
        foreach ($route->middleware() as $middleware) {
            if (isset($middlewareGroups[$middleware]) && in_array('tenancy', $middlewareGroups[$middleware], true)) {
                return true;
            }
        }

        return false;
    }
}
