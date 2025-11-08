<?php

use Illuminate\Routing\Route;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Stancl\Tenancy\Actions\CloneRoutesAsTenant;
use Stancl\Tenancy\Resolvers\PathTenantResolver;
use Illuminate\Support\Facades\Route as RouteFacade;
use function Stancl\Tenancy\Tests\pest;
use Illuminate\Routing\Exceptions\UrlGenerationException;

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

test('the clone action prefixes already prefixed routes correctly', function (bool $tenantParameterBeforePrefix) {
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

    $cloneAction = app(CloneRoutesAsTenant::class);
    $cloneAction
        ->tenantParameterBeforePrefix($tenantParameterBeforePrefix)
        ->handle();

    $expectedPrefix = $tenantParameterBeforePrefix ? '{tenant}/prefix' : 'prefix/{tenant}';

    $clonedRoutes = [
        RouteFacade::getRoutes()->getByName('tenant.home'),
        RouteFacade::getRoutes()->getByName('tenant.leadingAndTrailingSlash'),
        RouteFacade::getRoutes()->getByName('tenant.leadingSlash'),
        RouteFacade::getRoutes()->getByName('tenant.trailingSlash'),
    ];

    // The cloned route is prefixed correctly
    foreach ($clonedRoutes as $key => $route) {
        expect($route->getPrefix())->toBe($expectedPrefix);

        $clonedRouteUrl = route($route->getName(), ['tenant' => $tenant = Tenant::create()]);
        $expectedPrefixInUrl = $tenantParameterBeforePrefix ? "{$tenant->id}/prefix" : "prefix/{$tenant->id}";

        expect($clonedRouteUrl)
            // Original prefix does not occur in the cloned route's URL
            ->not()->toContain("prefix/{$tenant->id}/prefix")
            ->not()->toContain("//prefix")
            ->not()->toContain("prefix//")
            // Instead, the route is prefixed correctly
            ->toBe("http://localhost/{$expectedPrefixInUrl}/{$routes[$key]->getName()}");

        // The cloned route is accessible
        pest()->get($clonedRouteUrl)->assertOk();
    }
})->with([true, false]);

test('clone action trims trailing slashes from prefixes given to nested route groups', function (bool $tenantParameterBeforePrefix) {
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

    $cloneAction = app(CloneRoutesAsTenant::class);
    $cloneAction
        ->tenantParameterBeforePrefix($tenantParameterBeforePrefix)
        ->handle();

    $clonedLandingUrl = route('tenant.landing', ['tenant' => $tenant = Tenant::create()]);
    $clonedHomeRouteUrl = route('tenant.home', ['tenant' => $tenant]);

    $landingRoute = RouteFacade::getRoutes()->getByName('tenant.landing');
    $homeRoute = RouteFacade::getRoutes()->getByName('tenant.home');

    $expectedPrefix = $tenantParameterBeforePrefix ? '{tenant}/prefix' : 'prefix/{tenant}';
    $expectedPrefixInUrl = $tenantParameterBeforePrefix ? "{$tenant->id}/prefix" : "prefix/{$tenant->id}";

    expect($landingRoute->uri())->toBe($expectedPrefix);
    expect($homeRoute->uri())->toBe("{$expectedPrefix}/home");

    expect($clonedLandingUrl)
        ->not()->toContain("prefix//")
        ->toBe("http://localhost/{$expectedPrefixInUrl}");

    expect($clonedHomeRouteUrl)
        ->not()->toContain("prefix//")
        ->toBe("http://localhost/{$expectedPrefixInUrl}/home");
})->with([true, false]);

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

test('the cloned route can be scoped to a specified domain', function () {
    RouteFacade::domain('foo.localhost')->get('/foo', fn () => in_array('tenant', request()->route()->middleware()) ? 'tenant' : 'central')->name('foo')->middleware('clone');

    // Importantly, we CANNOT add a domain to the cloned route *if the original route didn't have a domain*.
    // This is due to the route registration order - the more strongly scoped route (= route with a domain)
    // must be registered first, so that Laravel tries that route first and only moves on if the domain check fails.
    $cloneAction = app(CloneRoutesAsTenant::class);
    // To keep the test simple we don't even need a tenant parameter
    $cloneAction->domain('bar.localhost')->addTenantParameter(false)->handle();

    expect(route('foo'))->toBe('http://foo.localhost/foo');
    expect(route('tenant.foo'))->toBe('http://bar.localhost/foo');
});

test('tenant parameter addition can be controlled by setting addTenantParameter', function (bool $addTenantParameter) {
    RouteFacade::domain('central.localhost')
        ->get('/foo', fn () => in_array('tenant', request()->route()->middleware()) ? 'tenant' : 'central')
        ->name('foo')
        ->middleware('clone');

    // By default this action also removes the domain
    $cloneAction = app(CloneRoutesAsTenant::class);
    $cloneAction->addTenantParameter($addTenantParameter)->handle();

    $clonedRoute = RouteFacade::getRoutes()->getByName('tenant.foo');

    // We only use the route() helper here, since once a request is made
    // the URL generator caches the request's domain and it affects route
    // generation for routes that do not have domain() specified (tenant.foo)
    expect(route('foo'))->toBe('http://central.localhost/foo');
    if ($addTenantParameter)
        expect(route('tenant.foo', ['tenant' => 'abc']))->toBe('http://localhost/abc/foo');
    else
        expect(route('tenant.foo'))->toBe('http://localhost/foo');

    // Original route still works
    $this->withoutExceptionHandling()->get(route('foo'))->assertSee('central');

    if ($addTenantParameter) {
        expect($clonedRoute->uri())->toContain('{tenant}');

        $this->withoutExceptionHandling()->get('http://localhost/abc/foo')->assertSee('tenant');
        $this->withoutExceptionHandling()->get('http://central.localhost/foo')->assertSee('central');
    } else {
        expect($clonedRoute->uri())->not()->toContain('{tenant}');

        $this->withoutExceptionHandling()->get('http://localhost/foo')->assertSee('tenant');
        $this->withoutExceptionHandling()->get('http://central.localhost/foo')->assertSee('central');
    }
})->with([true, false]);

test('existing context flags are removed during cloning', function () {
    RouteFacade::get('/foo', fn () => true)->name('foo')->middleware(['clone', 'central']);
    RouteFacade::get('/bar', fn () => true)->name('bar')->middleware(['clone', 'universal']);

    $cloneAction = app(CloneRoutesAsTenant::class);

    // Clone foo route
    $cloneAction->handle();
    expect(collect(RouteFacade::getRoutes()->get())->map->getName())
        ->toContain('tenant.foo');
    expect(tenancy()->getRouteMiddleware(RouteFacade::getRoutes()->getByName('tenant.foo')))
        ->not()->toContain('central');

    // Clone bar route
    $cloneAction->handle();
    expect(collect(RouteFacade::getRoutes()->get())->map->getName())
        ->toContain('tenant.foo', 'tenant.bar');
    expect(tenancy()->getRouteMiddleware(RouteFacade::getRoutes()->getByName('tenant.foo')))
        ->not()->toContain('universal');
});

test('cloning a route without a prefix or differing domains overrides the original route', function () {
    RouteFacade::get('/foo', fn () => true)->name('foo')->middleware(['clone']);

    expect(collect(RouteFacade::getRoutes()->get())->map->getName())->toContain('foo');

    $cloneAction = CloneRoutesAsTenant::make();
    $cloneAction->cloneRoute('foo')
        ->addTenantParameter(false)
        ->tenantParameterBeforePrefix(false)
        ->handle();

    expect(collect(RouteFacade::getRoutes()->get())->map->getName())->toContain('tenant.foo');
    expect(collect(RouteFacade::getRoutes()->get())->map->getName())->not()->toContain('foo');
});

test('addTenantMiddleware can be used to specify the tenant middleware for the cloned route', function () {
    RouteFacade::get('/foo', fn () => true)->name('foo')->middleware(['clone']);
    RouteFacade::get('/bar', fn () => true)->name('bar')->middleware(['clone']);

    $cloneAction = app(CloneRoutesAsTenant::class);

    $cloneAction->cloneRoute('foo')->addTenantMiddleware([InitializeTenancyByPath::class])->handle();
    expect(collect(RouteFacade::getRoutes()->get())->map->getName())->toContain('tenant.foo');
    $cloned = RouteFacade::getRoutes()->getByName('tenant.foo');
    expect($cloned->uri())->toBe('{tenant}/foo');
    expect($cloned->getName())->toBe('tenant.foo');
    expect(tenancy()->getRouteMiddleware($cloned))->toBe([InitializeTenancyByPath::class]);

    $cloneAction->cloneRoute('bar')
        ->addTenantMiddleware([InitializeTenancyByDomain::class])
        ->domain('foo.localhost')
        ->addTenantParameter(false)
        ->tenantParameterBeforePrefix(false)
        ->handle();
    expect(collect(RouteFacade::getRoutes()->get())->map->getName())->toContain('tenant.bar');
    $cloned = RouteFacade::getRoutes()->getByName('tenant.bar');
    expect($cloned->uri())->toBe('bar');
    expect($cloned->getName())->toBe('tenant.bar');
    expect($cloned->getDomain())->toBe('foo.localhost');
    expect(tenancy()->getRouteMiddleware($cloned))->toBe([InitializeTenancyByDomain::class]);
});
