<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Stancl\Tenancy\Concerns\UsableWithEarlyIdentification;
use Stancl\Tenancy\Enums\RouteMode;

/**
 * todo@name come up with a better name.
 *
 * Prevents accessing central domains in the tenant context/tenant domains in the central context.
 * The access isn't prevented if the request is trying to access a route flagged as 'universal',
 * or if this middleware should be skipped.
 *
 * @see UsableWithEarlyIdentification – more info about the skipping part
 */
class PreventAccessFromUnwantedDomains
{
    use UsableWithEarlyIdentification;

    /**
     * Set this property if you want to customize the on-fail behavior.
     */
    public static ?Closure $abortRequest;

    /** @return \Illuminate\Http\Response|mixed */
    public function handle(Request $request, Closure $next): mixed
    {
        $route = tenancy()->getRoute($request);

        if ($this->shouldBeSkipped($route) || tenancy()->routeIsUniversal($route)) {
            return $next($request);
        }

        if ($this->accessingTenantRouteFromCentralDomain($request, $route) || $this->accessingCentralRouteFromTenantDomain($request, $route)) {
            $abortRequest = static::$abortRequest ?? function () {
                abort(404);
            };

            return $abortRequest($request, $next);
        }

        return $next($request);
    }

    protected function accessingTenantRouteFromCentralDomain(Request $request, Route $route): bool
    {
        return tenancy()->getRouteMode($route) === RouteMode::TENANT // Current route's middleware context is tenant
            && $this->isCentralDomain($request); // The request comes from a domain that IS present in the configured `tenancy.identification.central_domains`
    }

    protected function accessingCentralRouteFromTenantDomain(Request $request, Route $route): bool
    {
        return tenancy()->getRouteMode($route) === RouteMode::CENTRAL // Current route's middleware context is central
            && ! $this->isCentralDomain($request); // The request comes from a domain that ISN'T present in the configured `tenancy.identification.central_domains`
    }

    /**
     * Check if the request's host name is present in the configured `tenancy.identification.central_domains`.
     */
    protected function isCentralDomain(Request $request): bool
    {
        return in_array($request->getHost(), config('tenancy.identification.central_domains'), true);
    }

    // todo@samuel technically not an identification middleware but probably ok to keep this here
    public function requestHasTenant(Request $request): bool
    {
        return false;
    }
}
