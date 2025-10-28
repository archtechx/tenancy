<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Actions;

use Closure;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use Stancl\Tenancy\Resolvers\PathTenantResolver;

/**
 * Clones either all existing routes for which shouldBeCloned() returns true
 * (by default, all routes with any middleware present in $cloneRoutesWithMiddleware),
 * or if any routes were manually added to $routesToClone using $action->cloneRoute($route),
 * clone just the routes in $routesToClone. This means that only the routes specified
 * by cloneRoute() (which can be chained infinitely -- you can specify as many routes as you want)
 * will be cloned.
 *
 * The main purpose of this action is to make the integration of packages
 * (e.g., Jetstream or Livewire) easier with path-based tenant identification.
 *
 * The default for $cloneRoutesWithMiddleware is ['clone'].
 * If $routesToClone is empty, all routes with any middleware specified in $cloneRoutesWithMiddleware will be cloned.
 * The middleware can be in a group, nested as deep as you want
 * (e.g. if a route has a 'foo' middleware which is a group containing the 'clone' middleware, the route will be cloned).
 *
 * You may customize $cloneRoutesWithMiddleware using cloneRoutesWithMiddleware() to make any middleware of your choice trigger cloning.
 * By providing a callback to shouldClone(), you can change how it's determined if a route should be cloned if you don't want to use middleware flags.
 *
 * Cloned routes are prefixed with '/{tenant}', flagged with 'tenant' middleware, and have their names prefixed with 'tenant.'.
 *
 * The addition of the tenant parameter can be controlled using addTenantParameter(true|false). Note that if you decide to disable
 * tenant parameter addition, the routes MUST differ in domains. This can be controlled using the domain(string|null) method. The
 * default behavior is to NOT set any domains on cloned routes -- unless specified otherwise using that method.
 *
 * The parameter name and prefix can be changed e.g. to `/{team}` and `team.` by configuring the path resolver (tenantParameterName and tenantRouteNamePrefix).
 * Routes with names that are already prefixed won't be cloned - but that's just the default behavior.
 * The cloneUsing() method allows you to completely override the default behavior and customize how the cloned routes will be defined.
 *
 * After cloning, only top-level middleware in $cloneRoutesWithMiddleware will be removed
 * from the new route (so by default, 'clone' will be omitted from the new route's MW).
 * Middleware groups are preserved as-is, even if they contain cloning middleware.
 *
 * Routes that already contain the tenant parameter or have names with the tenant prefix
 * will not be cloned.
 *
 * Example usage:
 * ```
 * Route::get('/foo', ...)->name('foo')->middleware('clone');
 * Route::get('/bar', ...)->name('bar')->middleware('universal');
 *
 * $cloneAction = app(CloneRoutesAsTenant::class);
 *
 * // Clone foo route as /{tenant}/foo/ and name it tenant.foo ('clone' middleware won't be present in the cloned route)
 * $cloneAction->handle();
 *
 * // Clone bar route as /{tenant}/bar and name it tenant.bar ('universal' middleware won't be present in the cloned route)
 * $cloneAction->cloneRoutesWithMiddleware(['universal'])->handle();
 *
 * Route::get('/baz', ...)->name('baz');
 *
 * // Clone baz route as /{tenant}/bar and name it tenant.baz ('universal' middleware won't be present in the cloned route)
 * $cloneAction->cloneRoute('baz')->handle();
 *
 * Route::domain('central.localhost')->get('/no-tenant-parameter', ...)->name('no-tenant-parameter')->middleware('clone');
 *
 * // Clone baz route as /no-tenant-parameter and name it tenant.no-tenant-parameter (the route won't have the tenant parameter)
 * // This can be useful with domain identification. Importantly, the original route MUST have a domain set. The domain of the
 * // cloned route can be customized using domain(string|null). By default, the cloned route will not be scoped to a domain,
 * // unless a domain() call is used. It's important to keep in mind that:
 * //   1. When addTenantParameter(false) is used, the paths will be the same, thus domains must differ.
 * //   2. If the original route (with the same path) has no domain, the cloned route will never be used due to registration order.
 * $cloneAction->addTenantParameter(false)->cloneRoutesWithMiddleware(['clone'])->cloneRoute('no-tenant-parameter')->handle();
 * ```
 *
 * Calling handle() will also clear the $routesToClone array.
 * This means that $action->cloneRoute('foo')->handle() will clone the 'foo' route, but subsequent calls to handle() will behave
 * as if cloneRoute() wasn't called at all ($routesToClone will be empty).
 * Note that calling handle() does not reset the other properties.
 *
 * @see Stancl\Tenancy\Resolvers\PathTenantResolver
 */
class CloneRoutesAsTenant
{
    protected array $routesToClone = [];
    protected bool $addTenantParameter = true;
    protected bool $tenantParameterBeforePrefix = true;
    protected string|null $domain = null;
    protected Closure|null $cloneUsing = null; // The callback should accept Route instance or the route name (string)
    protected Closure|null $shouldClone = null;
    protected array $cloneRoutesWithMiddleware = ['clone'];

    public function __construct(
        protected Router $router,
    ) {}

    /** Clone routes. This resets routesToClone() but not other config. */
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

            $this->createNewRoute($route);
        }

        // Clean up the routesToClone array after cloning so that subsequent calls aren't affected
        $this->routesToClone = [];

        $this->router->getRoutes()->refreshNameLookups();
        $this->router->getRoutes()->refreshActionLookups();
    }

    /**
     * Should a tenant parameter be added to the cloned route.
     *
     * The name of the parameter is controlled using PathTenantResolver::tenantParameterName().
     */
    public function addTenantParameter(bool $addTenantParameter): static
    {
        $this->addTenantParameter = $addTenantParameter;

        return $this;
    }

    /** The domain the cloned route should use. Set to null if it shouldn't be scoped to a domain. */
    public function domain(string|null $domain): static
    {
        $this->domain = $domain;

        return $this;
    }

    /** Provide a custom callback for cloning routes, instead of the default behavior. */
    public function cloneUsing(Closure|null $cloneUsing): static
    {
        $this->cloneUsing = $cloneUsing;

        return $this;
    }

    /** Specify which middleware should serve as "flags" telling this action to clone those routes. */
    public function cloneRoutesWithMiddleware(array $middleware): static
    {
        $this->cloneRoutesWithMiddleware = $middleware;

        return $this;
    }

    /**
     * Provide a custom callback for determining whether a route should be cloned.
     * Overrides the default middleware-based detection.
     * */
    public function shouldClone(Closure|null $shouldClone): static
    {
        $this->shouldClone = $shouldClone;

        return $this;
    }

    public function tenantParameterBeforePrefix(bool $tenantParameterBeforePrefix): static
    {
        $this->tenantParameterBeforePrefix = $tenantParameterBeforePrefix;

        return $this;
    }

    /** Clone an individual route. */
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

        $middleware = $this->processMiddlewareForCloning($action->get('middleware') ?? []);

        if ($name = $route->getName()) {
            $action->put('as', PathTenantResolver::tenantRouteNamePrefix() . $name);
        }

        if ($this->domain) {
            $action->put('domain', $this->domain);
        } elseif ($action->offsetExists('domain')) {
            $action->offsetUnset('domain');
        }

        $action->put('middleware', $middleware);

        if ($this->addTenantParameter) {
            $tenantParameter = '{' . PathTenantResolver::tenantParameterName() . '}';

            $newPrefix = $this->tenantParameterBeforePrefix
                ? $tenantParameter . '/' . $prefix
                : $prefix . '/' . $tenantParameter;

            $action->put('prefix', $newPrefix);
        }

        /** @var Route $newRoute */
        $newRoute = $this->router->$method($uri, $action->toArray());

        // Copy misc properties of the original route to the new route.
        $newRoute
            ->setBindingFields($route->bindingFields())
            ->setFallback($route->isFallback)
            ->setWheres($route->wheres)
            ->block($route->locksFor(), $route->waitsFor())
            ->withTrashed($route->allowsTrashedBindings())
            ->setDefaults($route->defaults);

        return $newRoute;
    }

    /** Removes top-level cloneRoutesWithMiddleware and adds 'tenant' middleware. */
    protected function processMiddlewareForCloning(array $middleware): array
    {
        $processedMiddleware = array_filter(
            $middleware,
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
