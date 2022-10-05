<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Events\InitializingTenancy;
use Stancl\Tenancy\Exceptions\RouteIsMissingTenantParameterException;
use Stancl\Tenancy\Resolvers\PathTenantResolver;
use Stancl\Tenancy\Tenancy;

class InitializeTenancyByPath extends IdentificationMiddleware
{
    public static ?Closure $onFail = null;

    public function __construct(
        protected Tenancy $tenancy,
        protected PathTenantResolver $resolver,
    ) {
    }

    /** @return \Illuminate\Http\Response|mixed */
    public function handle(Request $request, Closure $next): mixed
    {
        /** @var Route $route */
        $route = $request->route();

        // Only initialize tenancy if tenant is the first parameter
        // We don't want to initialize tenancy if the tenant is
        // simply injected into some route controller action.
        if ($route->parameterNames()[0] === PathTenantResolver::tenantParameterName()) {
            $this->setDefaultTenantForRouteParametersWhenTenancyIsInitialized();

            return $this->initializeTenancy(
                $request,
                $next,
                $route
            );
        } else {
            throw new RouteIsMissingTenantParameterException;
        }

        return $next($request);
    }

    protected function setDefaultTenantForRouteParametersWhenTenancyIsInitialized(): void
    {
        Event::listen(InitializingTenancy::class, function (InitializingTenancy $event) {
            /** @var Tenant $tenant */
            $tenant = $event->tenancy->tenant;

            URL::defaults([
                PathTenantResolver::tenantParameterName() => $tenant->getTenantKey(),
            ]);
        });
    }
}
