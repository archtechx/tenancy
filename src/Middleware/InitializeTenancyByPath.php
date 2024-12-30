<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Middleware;

use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Concerns\UsableWithEarlyIdentification;
use Stancl\Tenancy\Exceptions\RouteIsMissingTenantParameterException;
use Stancl\Tenancy\Resolvers\PathTenantResolver;
use Stancl\Tenancy\Tenancy;

/**
 * @see Stancl\Tenancy\Listeners\ForgetTenantParameter
 */
class InitializeTenancyByPath extends IdentificationMiddleware
{
    use UsableWithEarlyIdentification;

    public static ?Closure $onFail = null;

    public function __construct(
        protected Tenancy $tenancy,
        protected PathTenantResolver $resolver,
    ) {}

    /** @return \Illuminate\Http\Response|mixed */
    public function handle(Request $request, Closure $next): mixed
    {
        $route = tenancy()->getRoute($request);

        if ($this->shouldBeSkipped($route)) {
            return $next($request);
        }

        // Only initialize tenancy if the route has the tenant parameter.
        // We don't want to initialize tenancy if the tenant is
        // simply injected into some central route action.
        if (in_array(PathTenantResolver::tenantParameterName(), $route->parameterNames())) {
            return $this->initializeTenancy(
                $request,
                $next,
                $route
            );
        }

        throw new RouteIsMissingTenantParameterException;
    }

    /**
     * Request has tenant if the request's route has the tenant parameter.
     */
    public function requestHasTenant(Request $request): bool
    {
        return tenancy()->getRoute($request)->hasParameter(PathTenantResolver::tenantParameterName());
    }
}
