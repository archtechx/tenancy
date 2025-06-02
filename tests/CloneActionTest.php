<?php

use Illuminate\Routing\Route;
use Stancl\Tenancy\Enums\RouteMode;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Contracts\Http\Kernel;
use Stancl\Tenancy\Actions\CloneRoutesAsTenant;
use Stancl\Tenancy\Resolvers\PathTenantResolver;
use Illuminate\Support\Facades\Route as RouteFacade;
use Stancl\Tenancy\Tests\Etc\HasMiddlewareController;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use function Stancl\Tenancy\Tests\pest;

test('a route can be universal using path identification', function (array $routeMiddleware, array $globalMiddleware) {
    foreach ($globalMiddleware as $middleware) {
        if ($middleware === 'universal') {
            config(['tenancy.default_route_mode' => RouteMode::UNIVERSAL]);
        } else {
            app(Kernel::class)->pushMiddleware($middleware);
        }
    }

    RouteFacade::get('/foo', function () {
        return tenancy()->initialized
            ? 'Tenancy is initialized.'
            : 'Tenancy is not initialized.';
    })->middleware($routeMiddleware);

    config(['tenancy._tests.static_identification_middleware' => $routeMiddleware]);

    RouteFacade::get('/bar', [HasMiddlewareController::class, 'index']);

    /** @var CloneRoutesAsTenant $cloneRoutesAction */
    $cloneRoutesAction = app(CloneRoutesAsTenant::class);

    $cloneRoutesAction->handle();

    $tenantKey = Tenant::create()->getTenantKey();

    pest()->get("http://localhost/foo")
        ->assertSuccessful()
        ->assertSee('Tenancy is not initialized.');

    pest()->get("http://localhost/{$tenantKey}/foo")
        ->assertSuccessful()
        ->assertSee('Tenancy is initialized.');

    tenancy()->end();

    pest()->get("http://localhost/bar")
        ->assertSuccessful()
        ->assertSee('Tenancy is not initialized.');

    pest()->get("http://localhost/{$tenantKey}/bar")
        ->assertSuccessful()
        ->assertSee('Tenancy is initialized.');
})->with('path identification types');

test('CloneRoutesAsTenant registers prefixed duplicates of universal routes correctly', function (bool $kernelIdentification, bool $useController, string $tenantMiddleware) {
    $routeMiddleware = ['universal'];
    config(['tenancy.identification.path_identification_middleware' => [$tenantMiddleware]]);

    if ($kernelIdentification) {
        app(Kernel::class)->pushMiddleware($tenantMiddleware);
    } else {
        $routeMiddleware[] = $tenantMiddleware;
    }

    config(['tenancy.identification.resolvers.' . PathTenantResolver::class . '.tenant_parameter_name' => $tenantParameterName = 'team']);
    config(['tenancy.identification.resolvers.' . PathTenantResolver::class . '.tenant_route_name_prefix' => $tenantRouteNamePrefix = 'team-route.']);

    // Test that routes with controllers as well as routes with closure actions get cloned correctly
    $universalRoute = RouteFacade::get('/home', $useController ? Controller::class : fn () => tenant() ? 'Tenancy is initialized.' : 'Tenancy is not initialized.')->middleware($routeMiddleware)->name('home');
    $centralRoute = RouteFacade::get('/central', fn () => true)->name('central');

    config(['tenancy._tests.static_identification_middleware' => $routeMiddleware]);

    $universalRoute2 = RouteFacade::get('/bar', [HasMiddlewareController::class, 'index'])->name('second-home');

    expect($routes = RouteFacade::getRoutes()->get())->toContain($universalRoute)
        ->toContain($universalRoute2)
        ->toContain($centralRoute);

    /** @var CloneRoutesAsTenant $cloneRoutesAction */
    $cloneRoutesAction = app(CloneRoutesAsTenant::class);

    $cloneRoutesAction->handle();

    expect($routesAfterRegisteringDuplicates = RouteFacade::getRoutes()->get())
        ->toContain($universalRoute)
        ->toContain($centralRoute);

    $newRoutes = collect($routesAfterRegisteringDuplicates)->filter(fn ($route) => ! in_array($route, $routes));

    expect($newRoutes->first()->uri())->toBe('{' . $tenantParameterName . '}' . '/' . $universalRoute->uri());
    expect($newRoutes->last()->uri())->toBe('{' . $tenantParameterName . '}' . '/' . $universalRoute2->uri());

    // Universal flag is excluded from the route middleware
    expect(tenancy()->getRouteMiddleware($newRoutes->first()))
        ->toEqualCanonicalizing(
            array_values(array_filter(array_merge(tenancy()->getRouteMiddleware($universalRoute), ['tenant']),
            fn($middleware) => $middleware !== 'universal'))
        );

    // Universal flag is provided statically in the route's controller, so we cannot exclude it
    expect(tenancy()->getRouteMiddleware($newRoutes->last()))
        ->toEqualCanonicalizing(
            array_values(array_merge(tenancy()->getRouteMiddleware($universalRoute2), ['tenant']))
        );

    $tenant = Tenant::create();

    pest()->get(route($centralRouteName = $universalRoute->getName()))->assertSee('Tenancy is not initialized.');
    pest()->get(route($centralRouteName2 = $universalRoute2->getName()))->assertSee('Tenancy is not initialized.');
    pest()->get(route($tenantRouteName = $newRoutes->first()->getName(), [$tenantParameterName => $tenant->getTenantKey()]))->assertSee('Tenancy is initialized.');
    tenancy()->end();
    pest()->get(route($tenantRouteName2 = $newRoutes->last()->getName(), [$tenantParameterName => $tenant->getTenantKey()]))->assertSee('Tenancy is initialized.');

    expect($tenantRouteName)->toBe($tenantRouteNamePrefix . $universalRoute->getName());
    expect($tenantRouteName2)->toBe($tenantRouteNamePrefix . $universalRoute2->getName());
    expect($centralRouteName)->toBe($universalRoute->getName());
    expect($centralRouteName2)->toBe($universalRoute2->getName());
})->with([
    'kernel identification' => true,
    'route-level identification' => false,
// Creates a matrix (multiple with())
])->with([
    'use controller' => true,
    'use closure' => false
])->with([
    'path identification middleware' => InitializeTenancyByPath::class,
    'custom path identification middleware' => CustomInitializeTenancyByPath::class,
]);

test('CloneRoutesAsTenant only clones routes with path identification by default', function () {
    app(Kernel::class)->pushMiddleware(InitializeTenancyByPath::class);

    $currentRouteCount = fn () => count(RouteFacade::getRoutes()->get());

    $initialRouteCount = $currentRouteCount();

    // Path identification is used globally, and this route doesn't use a specific identification middleware, meaning path identification is used and the route should get cloned
    RouteFacade::get('/home', fn () => tenant() ? 'Tenancy initialized.' : 'Tenancy not initialized.')->middleware('universal')->name('home');
    // The route uses a specific identification middleware other than InitializeTenancyByPath – the route shouldn't get cloned
    RouteFacade::get('/home-domain-id', fn () => tenant() ? 'Tenancy initialized.' : 'Tenancy not initialized.')->middleware(['universal', InitializeTenancyByDomain::class])->name('home-domain-id');

    expect($currentRouteCount())->toBe($newRouteCount = $initialRouteCount + 2);

    /** @var CloneRoutesAsTenant $cloneRoutesAction */
    $cloneRoutesAction = app(CloneRoutesAsTenant::class);

    $cloneRoutesAction->handle();

    // Only one of the two routes gets cloned
    expect($currentRouteCount())->toBe($newRouteCount + 1);
});

test('custom callbacks can be used for cloning universal routes', function () {
    RouteFacade::get('/home', fn () => tenant() ? 'Tenancy initialized.' : 'Tenancy not initialized.')->middleware(['universal', InitializeTenancyByPath::class])->name($routeName = 'home');

    /** @var CloneRoutesAsTenant $cloneRoutesAction */
    $cloneRoutesAction = app(CloneRoutesAsTenant::class);
    $currentRouteCount = fn () => count(RouteFacade::getRoutes()->get());
    $initialRouteCount = $currentRouteCount();

    $cloneRoutesAction;

    // Skip cloning the 'home' route
    $cloneRoutesAction->cloneUsing($routeName, function (Route $route) {
        return;
    })->handle();

    // Expect route count to stay the same because the 'home' route cloning gets skipped
    expect($initialRouteCount)->toEqual($currentRouteCount());

    // Modify the 'home' route cloning so that a different route is cloned
    $cloneRoutesAction->cloneUsing($routeName, function (Route $route) {
        RouteFacade::get('/cloned-route', fn () => true)->name('new.home');
    })->handle();

    expect($currentRouteCount())->toEqual($initialRouteCount + 1);
});

test('cloning of specific routes can get skipped', function () {
    RouteFacade::get('/home', fn () => tenant() ? 'Tenancy initialized.' : 'Tenancy not initialized.')->middleware('universal')->name($routeName = 'home');

    /** @var CloneRoutesAsTenant $cloneRoutesAction */
    $cloneRoutesAction = app(CloneRoutesAsTenant::class);
    $currentRouteCount = fn () => count(RouteFacade::getRoutes()->get());
    $initialRouteCount = $currentRouteCount();

    // Skip cloning the 'home' route
    $cloneRoutesAction->skipRoute($routeName);

    $cloneRoutesAction->handle();

    // Expect route count to stay the same because the 'home' route cloning gets skipped
    expect($initialRouteCount)->toEqual($currentRouteCount());
});

test('routes except nonuniversal routes with path id mw are given the tenant flag after cloning', function (array $routeMiddleware, array $globalMiddleware) {
    foreach ($globalMiddleware as $middleware) {
        if ($middleware === 'universal') {
            config(['tenancy.default_route_mode' => RouteMode::UNIVERSAL]);
        } else {
            app(Kernel::class)->pushMiddleware($middleware);
        }
    }

    $route = RouteFacade::get('/home', fn () => tenant() ? 'Tenancy initialized.' : 'Tenancy not initialized.')
        ->middleware($routeMiddleware)
        ->name($routeName = 'home');

    app(CloneRoutesAsTenant::class)->handle();

    $clonedRoute = RouteFacade::getRoutes()->getByName('tenant.' . $routeName);

    // Non-universal routes with identification middleware are already considered tenant, so they don't get the tenant flag
    if (! tenancy()->routeIsUniversal($route) && tenancy()->routeHasIdentificationMiddleware($clonedRoute)) {
        expect($clonedRoute->middleware())->not()->toContain('tenant');
    } else {
        expect($clonedRoute->middleware())->toContain('tenant');
    }
})->with('path identification types');

test('routes with the clone flag get cloned without making the routes universal', function ($identificationMiddleware) {
    config(['tenancy.identification.path_identification_middleware' => [$identificationMiddleware]]);

    RouteFacade::get('/home', fn () => tenant() ? 'Tenancy initialized.' : 'Tenancy not initialized.')
        ->middleware(['clone', $identificationMiddleware])
        ->name($routeName = 'home');

    $tenant = Tenant::create();

    app(CloneRoutesAsTenant::class)->handle();

    $clonedRoute = RouteFacade::getRoutes()->getByName('tenant.' . $routeName);

    expect(array_values($clonedRoute->middleware()))->toEqualCanonicalizing(['tenant', $identificationMiddleware]);

    // The original route is not accessible
    pest()->get(route($routeName))->assertServerError();
    pest()->get(route($routeName, ['tenant' => $tenant]))->assertServerError();
    // The cloned route is a tenant route
    pest()->get(route('tenant.' . $routeName, ['tenant' => $tenant]))->assertSee('Tenancy initialized.');
})->with([InitializeTenancyByPath::class, CustomInitializeTenancyByPath::class]);

test('the clone action prefixes already prefixed routes correctly', function () {
    $routes = [
        RouteFacade::get('/home', fn () => tenant() ? 'Tenancy initialized.' : 'Tenancy not initialized.')
            ->middleware(['universal', InitializeTenancyByPath::class])
            ->name('home')
            ->prefix('prefix'),

        RouteFacade::get('/leadingAndTrailingSlash', fn () => tenant() ? 'Tenancy initialized.' : 'Tenancy not initialized.')
            ->middleware(['universal', InitializeTenancyByPath::class])
            ->name('leadingAndTrailingSlash')
            ->prefix('/prefix/'),

        RouteFacade::get('/leadingSlash', fn () => tenant() ? 'Tenancy initialized.' : 'Tenancy not initialized.')
            ->middleware(['universal', InitializeTenancyByPath::class])
            ->name('leadingSlash')
            ->prefix('/prefix'),

        RouteFacade::get('/trailingSlash', fn () => tenant() ? 'Tenancy initialized.' : 'Tenancy not initialized.')
            ->middleware(['universal', InitializeTenancyByPath::class])
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
        pest()->get($clonedRouteUrl)->assertSee('Tenancy initialized.');
    }
});

test('clone action trims trailing slashes from prefixes given to nested route groups', function () {
    RouteFacade::prefix('prefix')->group(function () {
        RouteFacade::prefix('')->group(function () {
            // This issue seems to only happen when there's a group with a prefix, then a group with an empty prefix, and then a / route
            RouteFacade::get('/', fn () => tenant() ? 'Tenancy initialized.' : 'Tenancy not initialized.')
                ->middleware(['universal', InitializeTenancyByPath::class])
                ->name('landing');

            RouteFacade::get('/home', fn () => tenant() ? 'Tenancy initialized.' : 'Tenancy not initialized.')
                ->middleware(['universal', InitializeTenancyByPath::class])
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

class CustomInitializeTenancyByPath extends InitializeTenancyByPath
{

}

dataset('path identification types', [
    'kernel identification' => [
        ['universal'], // Route middleware
        [InitializeTenancyByPath::class], // Global Global middleware
    ],
    'route-level identification' => [
        ['universal', InitializeTenancyByPath::class], // Route middleware
        [], // Global middleware
    ],
    'kernel identification + defaulting to universal routes' => [
        [], // Route middleware
        ['universal', InitializeTenancyByPath::class], // Global middleware
    ],
    'route-level identification + defaulting to universal routes' => [
        [InitializeTenancyByPath::class],  // Route middleware
        ['universal'], // Global middleware
    ],
]);
