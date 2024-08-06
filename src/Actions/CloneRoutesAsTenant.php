<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Actions;

use Closure;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Stancl\Tenancy\Enums\RouteMode;
use Stancl\Tenancy\Resolvers\PathTenantResolver;

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
    protected array $skippedRoutes = [
        'stancl.tenancy.asset',
    ];

    public function __construct(
        protected Router $router,
    ) {}

    public function handle(): void
    {
        $this->getRoutesToClone()->each(fn (Route $route) => $this->cloneRoute($route));

        $this->router->getRoutes()->refreshNameLookups();
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

    protected function getRoutesToClone(): Collection
    {
        $tenantParameterName = PathTenantResolver::tenantParameterName();

        /**
         * Clone all routes that:
         * - don't have the tenant parameter
         * - aren't in the $skippedRoutes array
         * - are using path identification (kernel or route-level).
         *
         * Non-universal cloned routes will only be available in the tenant context,
         * universal routes will be available in both contexts.
         */
        return collect($this->router->getRoutes()->get())->filter(function (Route $route) use ($tenantParameterName) {
            if (
                tenancy()->routeHasMiddleware($route, 'tenant') ||
                in_array($route->getName(), $this->skippedRoutes, true) ||
                in_array($tenantParameterName, $route->parameterNames(), true)
            ) {
                return false;
            }

            $pathIdentificationMiddleware = config('tenancy.identification.path_identification_middleware');
            $routeHasPathIdentificationMiddleware = tenancy()->routeHasMiddleware($route, $pathIdentificationMiddleware);
            $routeHasNonPathIdentificationMiddleware = tenancy()->routeHasIdentificationMiddleware($route) && ! $routeHasPathIdentificationMiddleware;
            $pathIdentificationMiddlewareInGlobalStack = tenancy()->globalStackHasMiddleware($pathIdentificationMiddleware);

            /**
             * The route should get cloned if:
             * - it has route-level path identification middleware, OR
             * - it uses kernel path identification (it doesn't have any route-level identification middleware) and the route is tenant or universal.
             *
             * The route is considered tenant if:
             * - it's flagged as tenant, OR
             * - it's not flagged as tenant or universal, but it has the identification middleware
             *
             * The route is considered universal if it's flagged as universal, and it doesn't have the tenant flag
             * (it's still considered universal if it has route-level path identification middleware + the universal flag).
             *
             * If the route isn't flagged, the context is determined using the default route mode.
             */
            $pathIdentificationUsed = (! $routeHasNonPathIdentificationMiddleware) &&
                ($routeHasPathIdentificationMiddleware || $pathIdentificationMiddlewareInGlobalStack);

            if (
                $pathIdentificationUsed &&
                (tenancy()->getRouteMode($route) === RouteMode::UNIVERSAL || tenancy()->routeHasMiddleware($route, 'clone'))
            ) {
                return true;
            }

            return false;
        });
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

        $this->copyMiscRouteProperties($route, $this->createNewRoute($route));
    }

    protected function createNewRoute(Route $route): Route
    {
        $method = strtolower($route->methods()[0]);
        $prefix = trim($route->getPrefix() ?? '', '/');
        $uri = $route->getPrefix() ? Str::after($route->uri(), $prefix) : $route->uri();

        $newRouteAction = collect($route->action)->tap(function (Collection $action) use ($route, $prefix) {
            /** @var array $routeMiddleware */
            $routeMiddleware = $action->get('middleware') ?? [];

            // Make the new route have the same middleware as the original route
            // Add the 'tenant' middleware to the new route
            // Exclude `universal` and `clone` middleware from the new route (it should only be flagged as tenant)
            $newRouteMiddleware = collect($routeMiddleware)
                ->merge(['tenant']) // Add 'tenant' flag
                ->filter(fn (string $middleware) => ! in_array($middleware, ['universal', 'clone']))
                ->toArray();

            $tenantRouteNamePrefix = PathTenantResolver::tenantRouteNamePrefix();

            // Make sure the route name has the tenant route name prefix
            $newRouteNamePrefix = $route->getName()
                ? $tenantRouteNamePrefix . Str::after($route->getName(), $tenantRouteNamePrefix)
                : null;

            return $action
                ->put('as', $newRouteNamePrefix)
                ->put('middleware', $newRouteMiddleware)
                ->put('prefix', $prefix . '/{' . PathTenantResolver::tenantParameterName() . '}');
        })->toArray();

        /** @var Route $newRoute */
        $newRoute = $this->router->$method($uri, $newRouteAction);

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
