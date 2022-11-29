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
        $route = $this->route($request);

        // Only initialize tenancy if tenant is the first parameter
        // We don't want to initialize tenancy if the tenant is
        // simply injected into some route controller action.
        if ($route->parameterNames()[0] === PathTenantResolver::tenantParameterName()) {
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

    protected function route(Request $request): Route
    {
        /** @var Route $route */
        $route = $request->route();

        if (! $route) {
            // Create a fake $route instance that has enough information for this middleware's needs
            $route = new Route($request->method(), $request->getUri(), []);
            /**
             * getPathInfo() returns the path except the root domain.
             * We fetch the first parameter because tenant parameter is *always* first.
             */
            $route->parameters[PathTenantResolver::tenantParameterName()] = explode('/', ltrim($request->getPathInfo(), '/'))[0];
            $route->parameterNames[] = PathTenantResolver::tenantParameterName();
        }

        return $route;
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
}
