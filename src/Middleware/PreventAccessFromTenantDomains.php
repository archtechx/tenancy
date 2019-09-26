<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Middleware;

use Closure;

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

        $isTenantRoute = in_array('tenancy', $request->route()->middleware());

        if ($isTenantDomain && ! $isTenantRoute) { // accessing web routes from tenant domains
            return redirect(config('tenancy.home_url'));
        }

        if ($isExemptDomain && $isTenantRoute) { // accessing tenant routes on web domains
            abort(404);
        }

        return $next($request);
    }
}
