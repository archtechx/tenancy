<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Actions;

use Closure;
use Illuminate\Config\Repository;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use Stancl\Tenancy\Enums\RouteMode;
use Stancl\Tenancy\PathIdentificationManager;

/**
 * The CloneRoutesAsTenant action clones
 * routes flagged with the 'universal' middleware,
 * all routes without a flag if the default route mode is universal,
 * and routes that directly use the InitializeTenancyByPath middleware.
 *
 * The main purpose of this action is to make the integration
 * of packages (e.g., Jetstream or Livewire) easier with path-based tenant identification.
 *
 * By default, universal routes are cloned as tenant routes (= they get flagged with the 'tenant' middleware)
 * and prefixed with the '/{tenant}' path prefix. Their name also gets prefixed with the tenant name prefix.
 *
 * Routes with the path identification middleware get cloned similarly, but only if they're not universal at the same time.
 * Unlike universal routes, these routes don't get the tenant flag,
 * because they don't need it (they're not universal, and they have the identification MW, so they're already considered tenant).
 *
 * You can use the `cloneUsing()` hook to customize the route definitions,
 * and the `skipRoute()` method to skip cloning of specific routes.
 * You can also use the $tenantParameterName and $tenantRouteNamePrefix
 * static properties to customize the tenant parameter name or the route name prefix.
 *
 * Note that routes already containing the tenant parameter or prefix won't be cloned.
 */
class CloneRoutesAsTenant
{
    protected array $cloneRouteUsing = [];
    protected array $skippedRoutes = [];

    public function __construct(
        protected Router $router,
        protected Repository $config,
    ) {
    }

    public function handle(): void
    {
        $tenantParameterName = PathIdentificationManager::getTenantParameterName();
        $routePrefix = '/{' . $tenantParameterName . '}';

        /** @var Collection<Route> $routesToClone Only clone non-skipped routes without the tenant parameter. */
        $routesToClone = collect($this->router->getRoutes()->get())->filter(function (Route $route) use ($tenantParameterName) {
            return ! (in_array($tenantParameterName, $route->parameterNames()) || in_array($route->getName(), $this->skippedRoutes));
        });

        if ($this->config->get('tenancy.default_route_mode') !== RouteMode::UNIVERSAL) {
            // Only clone routes with route-level path identification and universal routes
            $routesToClone = $routesToClone->where(function (Route $route) {
                $routeIsUniversal = tenancy()->routeHasMiddleware($route, 'universal');

                return PathIdentificationManager::pathIdentificationOnRoute($route) || $routeIsUniversal;
            });
        }

        $this->router->prefix($routePrefix)->group(fn () => $routesToClone->each(fn (Route $route) => $this->cloneRoute($route)));
    }

    /**
     * Make the action clone a specific route using the provided callback instead of the default one.
     */
    public function cloneUsing(string $routeName, Closure $callback): static
    {
        $this->cloneRouteUsing[$routeName] = $callback;

        return $this;
    }

    /**
     * Skip a route's cloning.
     */
    public function skipRoute(string $routeName): static
    {
        $this->skippedRoutes[] = $routeName;

        return $this;
    }

    /**
     * Clone a route using a callback specified in the $cloneRouteUsing property (using the cloneUsing method).
     * If there's no callback specified for the route, use the default way of cloning routes.
     */
    protected function cloneRoute(Route $route): void
    {
        $routeName = $route->getName();

        // If the route's cloning callback exists
        // Use the callback to clone the route instead of the default way of cloning routes
        if ($routeName && $customRouteCallback = data_get($this->cloneRouteUsing, $routeName)) {
            $customRouteCallback($route);

            return;
        }

        $routesAreUniversalByDefault = $this->config->get('tenancy.default_route_mode') === RouteMode::UNIVERSAL;
        $routeHasIdentificationMiddleware = tenancy()->routeHasIdentificationMiddleware($route);
        $routeHasPathIdentification = PathIdentificationManager::pathIdentificationOnRoute($route);
        $pathIdentificationMiddlewareInGlobalStack = PathIdentificationManager::pathIdentificationInGlobalStack();

        // Determine if the passed route should get cloned
        // The route should be cloned if it has path identification middleware
        // Or if the route doesn't have identification middleware and path identification middleware
        // Is not used globally or the routes are universal by default
        $shouldCloneRoute = $routeHasPathIdentification ||
            (! $routeHasIdentificationMiddleware && ($routesAreUniversalByDefault || $pathIdentificationMiddlewareInGlobalStack));

        if ($shouldCloneRoute) {
            $newRoute = $this->createNewRoute($route);
            $routeIsUniversal = tenancy()->routeHasMiddleware($newRoute, 'universal');

            // Add the 'tenant' flag to the new route if the route is universal
            // Or if it isn't universal and it doesn't have the identification middlware (= it isn't "flagged" as tenant by having the MW)
            if ((! $routeHasPathIdentification && ! $routeIsUniversal) || $routeIsUniversal || $routesAreUniversalByDefault) {
                $newRoute->middleware('tenant');
            }

            $this->copyMiscRouteProperties($route, $newRoute);
        }
    }

    protected function createNewRoute(Route $route): Route
    {
        $method = strtolower($route->methods()[0]);
        $routeName = $route->getName();
        $tenantRouteNamePrefix = PathIdentificationManager::getTenantRouteNamePrefix();

        /** @var Route $newRoute */
        $newRoute = $this->router->$method($route->uri(), $route->action);

        // Delete middleware from the new route and
        // Add original route middleware to ensure there's no duplicate middleware
        unset($newRoute->action['middleware']);

        $newRoute->middleware(tenancy()->getRouteMiddleware($route));

        if ($routeName && ! $route->named($tenantRouteNamePrefix . '*')) {
            // Clear the route name so that `name()` sets the route name instead of suffixing it
            unset($newRoute->action['as']);

            $newRoute->name($tenantRouteNamePrefix . $routeName);
        }

        return $newRoute;
    }

    /**
     * Copy misc properties of the original route to the new route.
     */
    protected function copyMiscRouteProperties(Route $originalRoute, Route $newRoute): void
    {
        $newRoute
            ->setBindingFields($originalRoute->bindingFields())
            ->setFallback($originalRoute->isFallback)
            ->setWheres($originalRoute->wheres)
            ->block($originalRoute->locksFor(), $originalRoute->waitsFor())
            ->withTrashed($originalRoute->allowsTrashedBindings())
            ->setDefaults($originalRoute->defaults);
    }
}
