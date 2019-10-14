<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Middleware;

use Closure;

/**
 * Prevent access from tenant domains to central routes and vice versa.
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
