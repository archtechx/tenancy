<?php

use Illuminate\Routing\Route;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Stancl\Tenancy\Actions\CloneRoutesAsTenant;
use Stancl\Tenancy\Resolvers\PathTenantResolver;
use Illuminate\Support\Facades\Route as RouteFacade;
use function Stancl\Tenancy\Tests\pest;

test('CloneRoutesAsTenant action clones routes with clone middleware by default', function () {
    config(['tenancy.identification.resolvers.' . PathTenantResolver::class . '.tenant_parameter_name' => 'team']);
    config(['tenancy.identification.resolvers.' . PathTenantResolver::class . '.tenant_route_name_prefix' => 'team-route.']);

    // Should not be cloned
    RouteFacade::get('/central', fn () => true)->name('central');

    // Should be cloned since no specific routes are passed to the action using cloneRoute() and the route has the 'clone' middleware
    RouteFacade::get('/foo', fn () => true)->middleware(['clone'])->name('foo');

    $originalRoutes = RouteFacade::getRoutes()->get();

    /** @var CloneRoutesAsTenant $cloneRoutesAction */
    $cloneRoutesAction = app(CloneRoutesAsTenant::class);

    $cloneRoutesAction->handle();

    $newRoutes = collect(RouteFacade::getRoutes()->get())->filter(fn ($route) => ! in_array($route, $originalRoutes));

    expect($newRoutes->count())->toEqual(1);

    $newRoute = $newRoutes->first();
    expect($newRoute->uri())->toBe('{team}/foo');

    $tenant = Tenant::create();

    expect($newRoute->getName())->toBe('team-route.foo');
    pest()->get(route('team-route.foo', ['team' => $tenant->id]))->assertOk();
    expect(tenancy()->getRouteMiddleware($newRoute))
        ->toContain('tenant')
        ->not()->toContain('clone');
});

test('CloneRoutesAsTenant action clones only specified routes when using cloneRoute()', function () {
    config(['tenancy.identification.resolvers.' . PathTenantResolver::class . '.tenant_parameter_name' => 'team']);
    config(['tenancy.identification.resolvers.' . PathTenantResolver::class . '.tenant_route_name_prefix' => 'team-route.']);

    // Should not be cloned
    RouteFacade::get('/central', fn () => true)->name('central');

    // Should not be cloned despite having clone middleware because cloneRoute() is used
    RouteFacade::get('/foo', fn () => true)->middleware(['clone'])->name('foo');

    // The only route that should be cloned
    $routeToClone = RouteFacade::get('/home', fn () => true)->name('home');

    $originalRoutes = RouteFacade::getRoutes()->get();

    /** @var CloneRoutesAsTenant $cloneRoutesAction */
    $cloneRoutesAction = app(CloneRoutesAsTenant::class);

    // If a specific route is passed to the action, clone only that route (cloneRoute() can be chained as many times as needed)
    $cloneRoutesAction->cloneRoute($routeToClone);

    $cloneRoutesAction->handle();

    $newRoutes = collect(RouteFacade::getRoutes()->get())->filter(fn ($route) => ! in_array($route, $originalRoutes));

    expect($newRoutes->count())->toEqual(1);

    $newRoute = $newRoutes->first();
    expect($newRoute->uri())->toBe('{team}/home');

    $tenant = Tenant::create();

    expect($newRoute->getName())->toBe('team-route.home');
    pest()->get(route('team-route.home', ['team' => $tenant->id]))->assertOk();
    expect(tenancy()->getRouteMiddleware($newRoute))
        ->toContain('tenant')
        ->not()->toContain('clone');

    // Verify that the route with clone middleware was NOT cloned
    expect(RouteFacade::getRoutes()->getByName('team-route.foo'))->toBeNull();
});

test('all routes with any of the middleware specified in cloneRoutesWithMiddleware will be cloned by default', function (array $cloneRoutesWithMiddleware) {
    RouteFacade::get('/foo', fn () => true)->name('foo');
    RouteFacade::get('/bar', fn () => true)->name('bar')->middleware(['clone']);
    RouteFacade::get('/baz', fn () => true)->name('baz')->middleware(['duplicate']);

    $currentRouteCount = fn () => count(RouteFacade::getRoutes()->get());
    $initialRouteCount = $currentRouteCount();

    /** @var CloneRoutesAsTenant $cloneRoutesAction */
    $cloneRoutesAction = app(CloneRoutesAsTenant::class);

    $cloneRoutesAction
        ->cloneRoutesWithMiddleware($cloneRoutesWithMiddleware)
        ->handle();

    // Each middleware is only used on a single route so we assert that the count of new routes matches the count of used middleware flags
    expect($currentRouteCount())->toEqual($initialRouteCount + count($cloneRoutesWithMiddleware));
})->with([
    [[]],
    [['duplicate']],
    [['clone', 'duplicate']],
]);

test('custom callback can be used for specifying if a route should be cloned', function () {
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

    expect(route('cloned.foo', absolute: false))->toBe('/cloned/foo');
    expect(route('cloned.bar', absolute: false))->toBe('/cloned/bar');

    pest()->get(route('cloned.foo'))->assertSee('cloned route');
    pest()->get(route('cloned.bar'))->assertSee('cloned route');
});

test('the clone action can clone specific routes either using name or route instance', function (bool $cloneRouteByName) {
    RouteFacade::get('/foo', fn () => true)->name('foo');
    $barRoute = RouteFacade::get('/bar', fn () => true)->name('bar');
    RouteFacade::get('/baz', fn () => true)->name('baz');

    $currentRouteCount = fn () => count(RouteFacade::getRoutes()->get());
    $initialRouteCount = $currentRouteCount();

    /** @var CloneRoutesAsTenant $cloneRoutesAction */
    $cloneRoutesAction = app(CloneRoutesAsTenant::class);

    // A route instance or a route name can be passed to cloneRoute()
    $cloneRoutesAction->cloneRoute($cloneRouteByName ? $barRoute->getName() : $barRoute)->handle();

    // Exactly one route should be cloned
    expect($currentRouteCount())->toEqual($initialRouteCount + 1);

    expect(RouteFacade::getRoutes()->getByName('tenant.bar'))->not()->toBeNull();
})->with([
    true,
    false,
]);

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
            ->not()->toContain("prefix/{$tenant->id}/prefix")
            ->not()->toContain("//prefix")
            ->not()->toContain("prefix//")
            // Instead, the route is prefixed correctly
            ->toBe("http://localhost/prefix/{$tenant->id}/{$routes[$key]->getName()}");

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

    $landingRoute = RouteFacade::getRoutes()->getByName('tenant.landing');
    $homeRoute = RouteFacade::getRoutes()->getByName('tenant.home');

    expect($landingRoute->uri())->toBe('prefix/{tenant}');
    expect($homeRoute->uri())->toBe('prefix/{tenant}/home');

    expect($clonedLandingUrl)
        ->not()->toContain("prefix//")
        ->toBe("http://localhost/prefix/{$tenant->id}");

    expect($clonedHomeRouteUrl)
        ->not()->toContain("prefix//")
        ->toBe("http://localhost/prefix/{$tenant->id}/home");
});

test('tenant routes are ignored from cloning and clone middleware in groups causes no issues', function () {
    // Should NOT be cloned, already has tenant parameter
    RouteFacade::get("/{tenant}/route-with-tenant-parameter", fn () => true)
        ->middleware(['clone'])
        ->name("tenant.route-with-tenant-parameter");

    // Should NOT be cloned, already has tenant name prefix
    RouteFacade::get("/route-with-tenant-name-prefix", fn () => true)
        ->middleware(['clone'])
        ->name("tenant.route-with-tenant-name-prefix");

    // Should NOT be cloned, already has tenant parameter + 'clone' middleware in group
    // 'clone' MW in groups won't be removed (this doesn't cause any issues)
    RouteFacade::middlewareGroup('group', ['auth', 'clone']);
    RouteFacade::get("/{tenant}/route-with-clone-in-mw-group", fn () => true)
        ->middleware('group')
        ->name("tenant.route-with-clone-in-mw-group");

    // SHOULD be cloned (has clone middleware)
    RouteFacade::get('/foo', fn () => true)
        ->middleware(['clone'])
        ->name('foo');

    // SHOULD be cloned (has nested clone middleware)
    RouteFacade::get('/bar', fn () => true)
        ->middleware(['group'])
        ->name('bar');

    $cloneAction = app(CloneRoutesAsTenant::class);
    $initialRouteCount = count(RouteFacade::getRoutes()->get());

    // Run clone action multiple times
    $cloneAction->handle();
    $firstRunCount = count(RouteFacade::getRoutes()->get());

    $cloneAction->handle();
    $secondRunCount = count(RouteFacade::getRoutes()->get());

    $cloneAction->handle();
    $thirdRunCount = count(RouteFacade::getRoutes()->get());

    // Two route should have been cloned, and only once
    expect($firstRunCount)->toBe($initialRouteCount + 2);
    // No new routes on subsequent runs
    expect($secondRunCount)->toBe($firstRunCount);
    expect($thirdRunCount)->toBe($firstRunCount);

    // Verify the correct routes were cloned
    expect(RouteFacade::getRoutes()->getByName('tenant.foo'))->toBeInstanceOf(Route::class);
    expect(RouteFacade::getRoutes()->getByName('tenant.bar'))->toBeInstanceOf(Route::class);

    // Tenant routes were not duplicated
    $allRouteNames = collect(RouteFacade::getRoutes()->get())->map->getName()->filter();
    expect($allRouteNames->filter(fn($name) => str_contains($name, 'route-with-tenant-parameter'))->count())->toBe(1);
    expect($allRouteNames->filter(fn($name) => str_contains($name, 'route-with-tenant-name-prefix'))->count())->toBe(1);
    expect($allRouteNames->filter(fn($name) => str_contains($name, 'route-with-clone-in-mw-group'))->count())->toBe(1);
});

test('clone action can be used fluently', function() {
    RouteFacade::get('/foo', fn () => true)->name('foo')->middleware('clone');
    RouteFacade::get('/bar', fn () => true)->name('bar')->middleware('universal');

    $cloneAction = app(CloneRoutesAsTenant::class);

    // Clone foo route
    $cloneAction->handle();
    expect(collect(RouteFacade::getRoutes()->get())->map->getName())
        ->toContain('tenant.foo');

    // Clone bar route
    $cloneAction->cloneRoutesWithMiddleware(['universal'])->handle();
    expect(collect(RouteFacade::getRoutes()->get())->map->getName())
        ->toContain('tenant.foo', 'tenant.bar');

    RouteFacade::get('/baz', fn () => true)->name('baz');

    // Clone baz route
    $cloneAction->cloneRoute('baz')->handle();
    expect(collect(RouteFacade::getRoutes()->get())->map->getName())
        ->toContain('tenant.foo', 'tenant.bar', 'tenant.baz');
});
