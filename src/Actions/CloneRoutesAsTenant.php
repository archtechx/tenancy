<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Actions;

use Closure;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Stancl\Tenancy\Resolvers\PathTenantResolver;

/**
 * Clones routes manually added to $routesToClone, or if $routesToClone is empty,
 * clones all existing routes for which shouldBeCloned returns true (by default, this means all routes
 * with any middleware that's present in $cloneRoutesWithMiddleware).
 * Cloned routes are prefixed with '/{tenant}', flagged with 'tenant' middleware,
 * and have their names prefixed with 'tenant.'.
 *
 * The main purpose of this action is to make the integration
 * of packages (e.g., Jetstream or Livewire) easier with path-based tenant identification.
 *
 * Customization:
 * - Use cloneRoutesWithMiddleware() to change the middleware in $cloneRoutesWithMiddleware
 * - Use shouldClone() to change which routes should be cloned
 * - Use cloneUsing() to customize route definitions
 * - Adjust PathTenantResolver's $tenantParameterName and $tenantRouteNamePrefix as needed
 *
 * Note that routes already containing the tenant parameter or prefix won't be cloned.
 */
class CloneRoutesAsTenant
{
    protected array $routesToClone = [];
    protected Closure|null $cloneUsing = null;
    protected Closure|null $shouldBeCloned = null;
    protected array $cloneRoutesWithMiddleware = ['clone'];

    public function __construct(
        protected Router $router,
    ) {}

    public function handle(): void
    {
        // If no routes were specified using cloneRoute(), get all routes that should be cloned
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
        $this->shouldBeCloned = $shouldClone;

        return $this;
    }

    public function cloneRoute(Route $route): static
    {
        $this->routesToClone[] = $route;

        return $this;
    }

    protected function shouldBeCloned(Route $route): bool
    {
        if ($this->shouldBeCloned) {
            return ($this->shouldBeCloned)($route);
        }

        return tenancy()->routeHasMiddleware($route, $this->cloneRoutesWithMiddleware);
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
            // Exclude $this->cloneRoutesWithMiddleware MW from the new route (it should only be flagged as tenant)
            $newRouteMiddleware = collect($routeMiddleware)
                ->merge(['tenant']) // Add 'tenant' flag
                ->filter(fn (string $middleware) => ! in_array($middleware, $this->cloneRoutesWithMiddleware))
                ->toArray();

            $tenantRouteNamePrefix = PathTenantResolver::tenantRouteNamePrefix();

            // Make sure the route name has the tenant route name prefix
            $newRouteName = $route->getName()
                ? $tenantRouteNamePrefix . Str::after($route->getName(), $tenantRouteNamePrefix)
                : null;

            return $action
                ->put('as', $newRouteName)
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
