<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Actions;

use Closure;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use Stancl\Tenancy\Resolvers\PathTenantResolver;

/**
 * Clones routes manually added to $routesToClone, or if $routesToClone is empty,
 * clones all existing routes for which shouldBeCloned returns true (by default, this means
 * all routes with any middleware that's present in $cloneRoutesWithMiddleware).
 *
 * The default value of $cloneRoutesWithMiddleware is ['clone'] which means that routes
 * with the 'clone' middleware will be cloned as described below. You may customize
 * either this array, to make other middleware trigger cloning, or by providing a callback
 * to shouldClone() to change how the logic determines if a route should be cloned.
 *
 * After cloning, only top-level middleware in $cloneRoutesWithMiddleware will be *removed*
 * from the new route (so in the default case, 'clone' will be stripped from the MW list).
 * Middleware groups are preserved as-is, even if they contain cloning middleware.
 *
 * Cloned routes are prefixed with '/{tenant}', flagged with 'tenant' middleware,
 * and have their names prefixed with 'tenant.'.
 * Routes with names that are already prefixed won't be cloned.
 *
 * If the config for the path resolver is customized, the parameter name and prefix
 * can be changed, e.g. to `/{team}` and `team.`.
 *
 * The main purpose of this action is to make the integration of packages
 * (e.g., Jetstream or Livewire) easier with path-based tenant identification.
 *
 * Customization:
 * - Use cloneRoutesWithMiddleware() to change the middleware in $cloneRoutesWithMiddleware
 * - Use shouldClone() to provide a custom callback that receives a Route instance and
 *   returns a boolean indicating whether the route should be cloned. This callback
 *   takes precedence over the default middleware-based logic. Return true to clone
 *   the route, false to skip it. The callback is called after the default logic that
 *   prevents cloning routes that are already considered tenant.
 * - Use cloneUsing() to customize route definitions
 * - Adjust PathTenantResolver's tenantParameterName and tenantRouteNamePrefix as needed in the config file
 *
 * Infinite cloning loops are prevented by skipping routes that already contain the tenant
 * parameter or have names with the tenant prefix.
 */
class CloneRoutesAsTenant
{
    protected array $routesToClone = [];
    protected Closure|null $cloneUsing = null; // The callback should accept Route instance or the route name (string)
    protected Closure|null $shouldClone = null;
    protected array $cloneRoutesWithMiddleware = ['clone'];

    public function __construct(
        protected Router $router,
    ) {}

    public function handle(): void
    {
        // If no routes were specified using cloneRoute(), get all routes
        // and for each, determine if it should be cloned
        if (! $this->routesToClone) {
            $this->routesToClone = collect($this->router->getRoutes()->get())
                ->filter(fn (Route $route) => $this->shouldBeCloned($route))
                ->all();
        }

        foreach ($this->routesToClone as $route) {
            // If the cloneUsing callback is set,
            // use the callback to clone the route instead of the default
            if ($this->cloneUsing) {
                ($this->cloneUsing)($route);

                continue;
            }

            if (is_string($route)) {
                $this->router->getRoutes()->refreshNameLookups();
                $route = $this->router->getRoutes()->getByName($route);
            }

            $this->copyMiscRouteProperties($route, $this->createNewRoute($route));
        }

        $this->router->getRoutes()->refreshNameLookups();
    }

    public function cloneUsing(Closure|null $cloneUsing): static
    {
        $this->cloneUsing = $cloneUsing;

        return $this;
    }

    public function cloneRoutesWithMiddleware(array $middleware): static
    {
        $this->cloneRoutesWithMiddleware = $middleware;

        return $this;
    }

    public function shouldClone(Closure|null $shouldClone): static
    {
        $this->shouldClone = $shouldClone;

        return $this;
    }

    public function cloneRoute(Route|string $route): static
    {
        $this->routesToClone[] = $route;

        return $this;
    }

    protected function shouldBeCloned(Route $route): bool
    {
        // Don't clone routes that already have tenant parameter or prefix
        if ($this->routeIsTenant($route)) {
            return false;
        }

        if ($this->shouldClone) {
            return ($this->shouldClone)($route);
        }

        return tenancy()->routeHasMiddleware($route, $this->cloneRoutesWithMiddleware);
    }

    protected function createNewRoute(Route $route): Route
    {
        $method = strtolower($route->methods()[0]);
        $prefix = trim($route->getPrefix() ?? '', '/');
        $uri = $route->getPrefix() ? Str::after($route->uri(), $prefix) : $route->uri();

        $action = collect($route->action);

        // Make the new route have the same middleware as the original route
        // Add the 'tenant' middleware to the new route
        // Exclude $this->cloneRoutesWithMiddleware MW from the new route (it should only be flagged as tenant)

        /** @var array $middleware */
        $middleware = $action->get('middleware') ?? [];
        $middleware = $this->processMiddlewareForCloning($middleware);
        $name = $route->getName();

        if ($name) {
            $name = PathTenantResolver::tenantRouteNamePrefix() . $name;
        }

        $action
            ->put('as', $name)
            ->put('middleware', $middleware)
            ->put('prefix', $prefix . '/{' . PathTenantResolver::tenantParameterName() . '}');

        /** @var Route $newRoute */
        $newRoute = $this->router->$method($uri, $action->toArray());

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

    /** Removes top-level cloneRoutesWithMiddleware and adds 'tenant' middleware. */
    protected function processMiddlewareForCloning(array $middlewares): array
    {
        // Filter out top-level cloneRoutesWithMiddleware and add the 'tenant' flag
        $processedMiddleware = array_filter(
            $middlewares,
            fn ($mw) => ! in_array($mw, $this->cloneRoutesWithMiddleware)
        );

        $processedMiddleware[] = 'tenant';

        return array_unique($processedMiddleware);
    }

    /** Check if route already has tenant parameter or name prefix. */
    protected function routeIsTenant(Route $route): bool
    {
        $routeHasTenantParameter = in_array(PathTenantResolver::tenantParameterName(), $route->parameterNames());
        $routeHasTenantPrefix = $route->getName() && str_starts_with($route->getName(), PathTenantResolver::tenantRouteNamePrefix());

        return $routeHasTenantParameter || $routeHasTenantPrefix;
    }
}
