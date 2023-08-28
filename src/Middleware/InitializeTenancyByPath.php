<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Stancl\Tenancy\Concerns\UsableWithEarlyIdentification;
use Stancl\Tenancy\Concerns\UsableWithUniversalRoutes;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Enums\RouteMode;
use Stancl\Tenancy\Events\InitializingTenancy;
use Stancl\Tenancy\Exceptions\RouteIsMissingTenantParameterException;
use Stancl\Tenancy\Overrides\TenancyUrlGenerator;
use Stancl\Tenancy\Resolvers\PathTenantResolver;
use Stancl\Tenancy\Tenancy;

/**
 * @see Stancl\Tenancy\Listeners\ForgetTenantParameter
 */
class InitializeTenancyByPath extends IdentificationMiddleware implements UsableWithUniversalRoutes
{
    use UsableWithEarlyIdentification;

    public static ?Closure $onFail = null;

    public function __construct(
        protected Tenancy $tenancy,
        protected PathTenantResolver $resolver,
    ) {
    }

    /** @return \Illuminate\Http\Response|mixed */
    public function handle(Request $request, Closure $next): mixed
    {
        $route = tenancy()->getRoute($request);

        if ($this->shouldBeSkipped($route)) {
            return $next($request);
        }

        // Used with *route-level* identification, takes precedence over what may have been configured for global stack middleware
        TenancyUrlGenerator::$prefixRouteNames = true;

        // Only initialize tenancy if the route has the tenant parameter.
        // We don't want to initialize tenancy if the tenant is
        // simply injected into some route controller action.
        if (in_array(PathTenantResolver::tenantParameterName(), $route->parameterNames())) {
            $this->setDefaultTenantForRouteParametersWhenInitializingTenancy();

            return $this->initializeTenancy(
                $request,
                $next,
                $route
            );
        } else {
            throw new RouteIsMissingTenantParameterException;
        }
    }

    protected function setDefaultTenantForRouteParametersWhenInitializingTenancy(): void
    {
        Event::listen(InitializingTenancy::class, function (InitializingTenancy $event) {
            /** @var Tenant $tenant */
            $tenant = $event->tenancy->tenant;

            URL::defaults([
                PathTenantResolver::tenantParameterName() => $tenant->getTenantKey(),
            ]);
        });
    }

    /**
     * Path identification request has a tenant if the middleware context is tenant.
     *
     * With path identification, we can just check the MW context because we're cloning the universal routes,
     * and the routes are flagged with the 'tenant' MW group (= their MW context is tenant).
     *
     * With other identification middleware, we have to determine the context differently because we only have one
     * truly universal route available ('truly universal' because with path identification, applying 'universal' to a route just means that
     * it should get cloned, whereas with other ID MW, it means that the route you apply the 'universal' flag to will be accessible in both contexts).
     */
    public function requestHasTenant(Request $request): bool
    {
        return tenancy()->getRouteMode(tenancy()->getRoute($request)) === RouteMode::TENANT;
    }
}
