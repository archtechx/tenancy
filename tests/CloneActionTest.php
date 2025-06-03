<?php

use Illuminate\Routing\Route;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Stancl\Tenancy\Actions\CloneRoutesAsTenant;
use Stancl\Tenancy\Resolvers\PathTenantResolver;
use Illuminate\Support\Facades\Route as RouteFacade;
use function Stancl\Tenancy\Tests\pest;

test('CloneRoutesAsTenant action clones routes correctly', function (Route|null $routeToClone) {
    config(['tenancy.identification.resolvers.' . PathTenantResolver::class . '.tenant_parameter_name' => $tenantParameterName = 'team']);
    config(['tenancy.identification.resolvers.' . PathTenantResolver::class . '.tenant_route_name_prefix' => $tenantRouteNamePrefix = 'team-route.']);

    // Should not be cloned
    RouteFacade::get('/central', fn () => true)->name('central');

    $routesThatShouldBeCloned = $routeToClone
        ? [$routeToClone]
        // Should be cloned if no specific routes are passed to the action using cloneRoute()
        : [RouteFacade::get('/foo', fn () => true)->middleware(['clone'])->name('foo')];

    $originalRoutes = RouteFacade::getRoutes()->get();

    /** @var CloneRoutesAsTenant $cloneRoutesAction */
    $cloneRoutesAction = app(CloneRoutesAsTenant::class);

    // If a specific route is passed to the action, clone only that route (cloneRoute() can be chained as many times as needed)
    if ($routeToClone) {
        $cloneRoutesAction->cloneRoute($routeToClone);
    }

    $cloneRoutesAction->handle();

    $newRoutes = collect(RouteFacade::getRoutes()->get())->filter(fn ($route) => ! in_array($route, $originalRoutes));

    expect($newRoutes->count())->toEqual(count($routesThatShouldBeCloned));

    foreach ($newRoutes as $route) {
        expect($route->uri())->toStartWith('{' . $tenantParameterName . '}' . '/');

        $tenant = Tenant::create();

        expect($route->getName())->toBe($tenantRouteNamePrefix . str($route->getName())->afterLast(($tenantRouteNamePrefix)));
        pest()->get(route($route->getName(), [$tenantParameterName => $tenant->getTenantKey()]))->assertOk();
        expect(tenancy()->getRouteMiddleware($route))
            ->toContain('tenant')
            ->not()->toContain('clone');
    }
})->with([
    null, // Clone all routes for which shouldBeCloned returns true
    fn () => RouteFacade::get('/home', fn () => true)->name('home'), // The only route that should be cloned
]);

test('all routes with any of the middleware specified in cloneRoutesWithMiddleware will be cloned by default', function(array $cloneRoutesWithMiddleware) {
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

    expect($currentRouteCount())->toEqual($initialRouteCount + count($cloneRoutesWithMiddleware));
})->with([
    [[]],
    [['duplicate']],
    [['clone', 'duplicate']],
]);

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

test('the clone action can clone specific routes', function(bool $cloneRouteByName) {
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

test('clone middleware within middleware groups is properly handled during cloning', function () {
    // Simple MW group with 'clone' flag
    RouteFacade::middlewareGroup('simple-group', ['auth', 'clone']);

    // Define nested middleware groups 3 levels deep
    RouteFacade::middlewareGroup('level-3-group', ['clone']);
    RouteFacade::middlewareGroup('level-2-group', ['auth', 'level-3-group']);
    RouteFacade::middlewareGroup('nested-group', ['web', 'level-2-group']);

    // Create routes using both simple and nested middleware groups
    RouteFacade::get('/simple', fn () => true)
        ->middleware('simple-group')
        ->name('simple');

    RouteFacade::get('/nested', fn () => true)
        ->middleware('nested-group')
        ->name('nested');

    app(CloneRoutesAsTenant::class)->handle();

    // Test simple middleware group handling
    $clonedSimpleRoute = RouteFacade::getRoutes()->getByName('tenant.simple');
    expect($clonedSimpleRoute)->not()->toBeNull();

    $simpleRouteMiddleware = tenancy()->getRouteMiddleware($clonedSimpleRoute);
    expect($simpleRouteMiddleware)
        ->toContain('auth', 'tenant')
        ->not()->toContain('clone', 'simple-group');

    // Test nested middleware group handling (3 levels deep)
    $clonedNestedRoute = RouteFacade::getRoutes()->getByName('tenant.nested');
    expect($clonedNestedRoute)->not()->toBeNull();

    $nestedRouteMiddleware = tenancy()->getRouteMiddleware($clonedNestedRoute);
    expect($nestedRouteMiddleware)
        ->toContain('web', 'auth', 'tenant')
        ->not()->toContain('clone')
        // Should not contain any group names - middleware should be extracted
        ->not()->toContain('nested-group', 'level-2-group', 'level-3-group');
});
