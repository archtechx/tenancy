<?php

use Illuminate\Routing\Route;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Stancl\Tenancy\Actions\CloneRoutesAsTenant;
use Stancl\Tenancy\Resolvers\PathTenantResolver;
use Illuminate\Support\Facades\Route as RouteFacade;
use function Stancl\Tenancy\Tests\pest;

test('CloneRoutesAsTenant registers prefixed duplicates of routes correctly', function () {
    config(['tenancy.identification.resolvers.' . PathTenantResolver::class . '.tenant_parameter_name' => $tenantParameterName = 'team']);
    config(['tenancy.identification.resolvers.' . PathTenantResolver::class . '.tenant_route_name_prefix' => $tenantRouteNamePrefix = 'team-route.']);

    // Test that routes with controllers as well as routes with closure actions get cloned correctly
    $route = RouteFacade::get('/home', fn () => true)->middleware(['clone'])->name('home');

    expect($routes = RouteFacade::getRoutes()->get())->toContain($route);

    /** @var CloneRoutesAsTenant $cloneRoutesAction */
    $cloneRoutesAction = app(CloneRoutesAsTenant::class);

    $cloneRoutesAction->handle();

    $newRoutes = collect(RouteFacade::getRoutes()->get())->filter(fn ($route) => ! in_array($route, $routes));

    expect($newRoutes->first()->uri())->toBe('{' . $tenantParameterName . '}' . '/' . $route->uri());

    // The 'clone' flag is excluded from the route middleware
    expect(tenancy()->getRouteMiddleware($newRoutes->first()))
        ->toEqualCanonicalizing(
            array_values(array_filter(array_merge(tenancy()->getRouteMiddleware($route), ['tenant']),
            fn($middleware) => $middleware !== 'clone'))
        );

    $tenant = Tenant::create();

    pest()->get(route($tenantRouteName = $newRoutes->first()->getName(), [$tenantParameterName => $tenant->getTenantKey()]))->assertOk();

    expect($tenantRouteName)->toBe($tenantRouteNamePrefix . $route->getName());
});

test('custom callback can be used for determining if a route should be cloned', function () {
    RouteFacade::get('/home', fn () => true)->name('home');

    /** @var CloneRoutesAsTenant $cloneRoutesAction */
    $cloneRoutesAction = app(CloneRoutesAsTenant::class);
    $currentRouteCount = fn () => count(RouteFacade::getRoutes()->get());
    $initialRouteCount = $currentRouteCount();

    // No routes should be cloned
    $cloneRoutesAction
        ->shouldClone(fn (Route $route) => false)
        ->handle();

    // Expect route count to stay the same because cloning essentially gets turned off
    expect($initialRouteCount)->toEqual($currentRouteCount());

    // Only the 'home' route should be cloned
    $cloneRoutesAction
        ->shouldClone(fn (Route $route) => $route->getName() === 'home')
        ->handle();

    expect($currentRouteCount())->toEqual($initialRouteCount + 1);
});

test('custom callbacks can be used for customizing the creation of the cloned routes', function () {
    RouteFacade::get('/foo', fn () => true)->name('foo')->middleware(['clone']);
    RouteFacade::get('/bar', fn () => true)->name('bar')->middleware(['clone']);

    /** @var CloneRoutesAsTenant $cloneRoutesAction */
    $cloneRoutesAction = app(CloneRoutesAsTenant::class);

    $cloneRoutesAction
        ->cloneUsing(function (Route $route) {
            RouteFacade::get('/cloned/' . $route->uri(), fn () => 'cloned route')->name('cloned.' . $route->getName());
        })->handle();

    pest()->get(route('cloned.foo'))->assertSee('cloned route');
    pest()->get(route('cloned.bar'))->assertSee('cloned route');
});

test('the clone action can clone specific routes', function() {
    RouteFacade::get('/foo', fn () => true)->name('foo')->middleware(['clone']);
    $barRoute = RouteFacade::get('/bar', fn () => true)->name('bar')->middleware(['clone']);

    $currentRouteCount = fn () => count(RouteFacade::getRoutes()->get());
    $initialRouteCount = $currentRouteCount();

    /** @var CloneRoutesAsTenant $cloneRoutesAction */
    $cloneRoutesAction = app(CloneRoutesAsTenant::class);

    $cloneRoutesAction->cloneRoute($barRoute)->handle();

    // Exactly one route should be cloned
    expect($currentRouteCount())->toEqual($initialRouteCount + 1);

    expect(RouteFacade::getRoutes()->getByName('tenant.bar'))->not()->toBeNull();
});

test('the clone action prefixes already prefixed routes correctly', function () {
    $routes = [
        RouteFacade::get('/home', fn () => true)
            ->middleware(['clone'])
            ->name('home')
            ->prefix('prefix'),

        RouteFacade::get('/leadingAndTrailingSlash', fn () => true)
            ->middleware(['clone'])
            ->name('leadingAndTrailingSlash')
            ->prefix('/prefix/'),

        RouteFacade::get('/leadingSlash', fn () => true)
            ->middleware(['clone'])
            ->name('leadingSlash')
            ->prefix('/prefix'),

        RouteFacade::get('/trailingSlash', fn () => true)
            ->middleware(['clone'])
            ->name('trailingSlash')
            ->prefix('prefix/'),
    ];

    app(CloneRoutesAsTenant::class)->handle();

    $clonedRoutes = [
        RouteFacade::getRoutes()->getByName('tenant.home'),
        RouteFacade::getRoutes()->getByName('tenant.leadingAndTrailingSlash'),
        RouteFacade::getRoutes()->getByName('tenant.leadingSlash'),
        RouteFacade::getRoutes()->getByName('tenant.trailingSlash'),
    ];

    // The cloned route is prefixed correctly
    foreach ($clonedRoutes as $key => $route) {
        expect($route->getPrefix())->toBe("prefix/{tenant}");

        $clonedRouteUrl = route($route->getName(), ['tenant' => $tenant = Tenant::create()]);

        expect($clonedRouteUrl)
            // Original prefix does not occur in the cloned route's URL
            ->not()->toContain("prefix/{$tenant->getTenantKey()}/prefix")
            ->not()->toContain("//prefix")
            ->not()->toContain("prefix//")
            // Route is prefixed correctly
            ->toBe("http://localhost/prefix/{$tenant->getTenantKey()}/{$routes[$key]->getName()}");

        // The cloned route is accessible
        pest()->get($clonedRouteUrl)->assertOk();
    }
});

test('clone action trims trailing slashes from prefixes given to nested route groups', function () {
    RouteFacade::prefix('prefix')->group(function () {
        RouteFacade::prefix('')->group(function () {
            // This issue seems to only happen when there's a group with a prefix, then a group with an empty prefix, and then a / route
            RouteFacade::get('/', fn () => true)
                ->middleware(['clone'])
                ->name('landing');

            RouteFacade::get('/home', fn () => true)
                ->middleware(['clone'])
                ->name('home');
        });
    });

    app(CloneRoutesAsTenant::class)->handle();

    $clonedLandingUrl = route('tenant.landing', ['tenant' => $tenant = Tenant::create()]);
    $clonedHomeRouteUrl = route('tenant.home', ['tenant' => $tenant]);

    expect($clonedLandingUrl)
        ->not()->toContain("prefix//")
        ->toBe("http://localhost/prefix/{$tenant->getTenantKey()}");

    expect($clonedHomeRouteUrl)
        ->not()->toContain("prefix//")
        ->toBe("http://localhost/prefix/{$tenant->getTenantKey()}/home");
});
