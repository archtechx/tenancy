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
        $is_an_exempt_domain = in_array($request->getHost(), config('tenancy.exempt_domains'));
        $is_a_tenant_domain = ! $is_an_exempt_domain;

        $is_a_tenant_route = in_array('tenancy', $request->route()->middleware());

        if ($is_a_tenant_domain && ! $is_a_tenant_route) { // accessing web routes from tenant domains
            return redirect(config('tenancy.home_url'));
        }

        if ($is_an_exempt_domain && $is_a_tenant_route) { // accessing tenant routes on web domains
            abort(404);
        }

        return $next($request);
    }
}
