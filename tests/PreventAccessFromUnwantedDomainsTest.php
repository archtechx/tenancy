<?php

declare(strict_types=1);

use Stancl\Tenancy\Enums\RouteMode;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Middleware\PreventAccessFromUnwantedDomains;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomainOrSubdomain;
use Stancl\Tenancy\Tests\Etc\EarlyIdentification\ControllerWithMiddleware;
use function Stancl\Tenancy\Tests\pest;

test('correct routes are accessible in route-level identification', function (RouteMode $defaultRouteMode) {
    config()->set([
        'tenancy.default_route_mode' => $defaultRouteMode,
    ]);

    if ($defaultRouteMode === RouteMode::TENANT) {
        // Apply `central` middleware to central routes if routes default to `tenant`
        $centralMiddleware = ['central', PreventAccessFromUnwantedDomains::class];
        $tenantMiddleware = [PreventAccessFromUnwantedDomains::class, InitializeTenancyByDomain::class];
    } else {
        // Apply `tenant` middleware to `tenant` routes if routes default to `central`
        $centralMiddleware = [PreventAccessFromUnwantedDomains::class];
        $tenantMiddleware = ['tenant', PreventAccessFromUnwantedDomains::class, InitializeTenancyByDomain::class];
    }

    // Central route
    Route::get('central-route', function () {
        return 'central-route';
    })->middleware($centralMiddleware);

    // Tenant route
    Route::get('tenant-route', function () {
        return 'tenant-route';
    })->middleware($tenantMiddleware);

    $tenant = Tenant::create();
    $tenant->domains()->create([
        'domain' => 'foo.localhost',
    ]);

    // Accessing tenant routes on central domains and vice versa is not allowed
    pest()->get('http://localhost/tenant-route')->assertNotFound();
    pest()->get('http://foo.localhost/central-route')->assertNotFound();

    // Accessing central routes from central domains and vice versa is allowed
    pest()->get('http://localhost/central-route')->assertOk();
    pest()->get('http://foo.localhost/tenant-route')->assertOk();
})->with([
    'default to tenant routes' => RouteMode::TENANT,
    'default to central routes' => RouteMode::CENTRAL,
]);

test('correct routes are accessible in kernel identification', function (RouteMode $defaultRouteMode) {
    // Defaulting to tenant routes only works when using identification middleware globally
    app(Kernel::class)->pushMiddleware(PreventAccessFromUnwantedDomains::class);
    app(Kernel::class)->pushMiddleware(InitializeTenancyByDomain::class);

    config()->set([
        'tenancy.default_route_mode' => $defaultRouteMode,
    ]);

    $defaultToTenantRoutes = $defaultRouteMode === RouteMode::TENANT;

    // Test that if we're defaulting to a route mode, we don't have to specify the mode middleware ('tenant'/'central') explicitly
    if ($defaultToTenantRoutes) {
        // Apply `central` middleware to central routes if routes default to tenant context
        $centralMiddleware = ['central'];
        $tenantMiddleware = [];
    } else {
        // Apply `tenant` middleware to tenant routes if routes default to `central`
        $centralMiddleware = [];
        $tenantMiddleware = ['tenant'];
    }

    // Central route
    Route::get('central-route', function () {
        return 'central-route';
    })->middleware($centralMiddleware);

    // Tenant route
    Route::get('tenant-route', function () {
        return 'tenant-route';
    })->middleware($tenantMiddleware);

    // Route without the mode middleware
    Route::get('package-route', function () {
        return 'package-route';
    });

    $tenant = Tenant::create();
    $tenant->domains()->create([
        'domain' => 'foo.localhost',
    ]);

    // Central route on central domain is accessible
    pest()->get('http://localhost/central-route')->assertOk();
    expect(tenancy()->initialized)->toBeFalse();

    // Central route on tenant domain is not accessible
    pest()->get('http://foo.localhost/central-route')->assertNotFound();
    expect(tenancy()->initialized)->toBeFalse();

    // Tenant route on tenant domain is accessible
    pest()->get('http://foo.localhost/tenant-route')->assertOk();
    expect(tenancy()->initialized)->toBeTrue();
    tenancy()->end();

    // Tenant route on central domain is not accessible
    pest()->get('http://localhost/tenant-route')->assertNotFound();
    expect(tenancy()->initialized)->toBeFalse();

    if ($defaultToTenantRoutes) {
        // Routes default to tenant – package route is accessible from `tenant` domains
        pest()->get('http://foo.localhost/package-route')->assertOk();
        expect(tenancy()->initialized)->toBeTrue();
        tenancy()->end();

        // Package route isn't accessible from `central` domains
        pest()->get('http://localhost/package-route')->assertNotFound();
    } else {
        // Routes default to central – package route is accessible from `central` domains
        pest()->get('http://localhost/package-route')->assertOk();
        expect(tenancy()->initialized)->toBeFalse();

        // Package route isn't accessible from `tenant` domains
        pest()->get('http://foo.localhost/package-route')->assertNotFound();
    }
})->with([
    'default to tenant routes' => RouteMode::TENANT,
    'default to central routes' => RouteMode::CENTRAL,
]);

test('kernel PreventAccessFromUnwantedDomains does not get skipped when route level domain identification is used', function (string $domainIdentificationMiddleware, string $domain) {
    // With route-level *domain identification* MW (without PreventAccessFromUnwantedDomains)
    // PreventAccessFromUnwantedDomains shouldn't be skipped
    config([
        'tenancy.test_service_token' => 'token:central',
    ]);

    app(Kernel::class)->pushMiddleware(PreventAccessFromUnwantedDomains::class);
    Route::middlewareGroup('tenant', [$domainIdentificationMiddleware]);

    Route::get('tenant-route', [ControllerWithMiddleware::class, 'index'])->middleware('tenant')->name('tenant-route');
    Route::get('central-route', [ControllerWithMiddleware::class, 'index'])->middleware('central')->name('central-route');

    $tenant = Tenant::create();
    $tenant->domains()->create([
        'domain' => $domain,
    ]);

    if ($domain === 'foo') {
        $domain = 'foo.localhost';
    }

    // Tenant route is not accessible on central domain
    pest()->get('http://localhost/tenant-route')->assertNotFound();
    expect(tenancy()->initialized)->toBeFalse();

    // Central route is not accessible on tenant domain
    pest()->get("http://$domain/central-route")->assertNotFound();
    expect(tenancy()->initialized)->toBeFalse();

    // Tenant route is accessible on tenant domain
    pest()->get("http://$domain/tenant-route")->assertOk();
    expect(tenancy()->initialized)->toBeTrue();
    tenancy()->end();

    // Central route is accessible on central domain
    pest()->get('http://localhost/central-route')->assertOk();
    expect(tenancy()->initialized)->toBeFalse();
})->with([
    'domain identification mw' => [InitializeTenancyByDomain::class, 'foo.test'],
    'subdomain identification mw' => [InitializeTenancyBySubdomain::class, 'foo'],
    'domainOrSubdomain identification mw using domain' => [InitializeTenancyByDomainOrSubdomain::class, 'foo.test'],
    'domainOrSubdomain identification mw using subdomain' => [InitializeTenancyByDomainOrSubdomain::class, 'foo'],
]);

test('placement of domain identification and access prevention middleware can get mixed', function (
    array $routeMiddleware,
    array $globalMiddleware,
    array $centralRouteMiddleware
) {
    config([
        'tenancy.test_service_token' => 'token:central',
    ]);

    foreach ($globalMiddleware as $middleware) {
        app(Kernel::class)->pushMiddleware($middleware);
    }

    // Make sure the central route has the prevention MW
    // If it isn't used globally and it's not passed in $centralRouteMiddleware
    if (! in_array(PreventAccessFromUnwantedDomains::class, array_merge($centralRouteMiddleware, $globalMiddleware))) {
        $centralRouteMiddleware[] = PreventAccessFromUnwantedDomains::class;
    }

    $tenant = Tenant::create();
    $subdomain = $tenant->domains()->create(['domain' => 'foo'])->domain;

    Route::get('tenant-route', fn () => 'tenant route')->middleware(['tenant', ...$routeMiddleware]);
    Route::get('central-route', fn () => 'central route')->middleware($centralRouteMiddleware);

    pest()->get("http://$subdomain.localhost/tenant-route")->assertOk();
    expect(tenancy()->initialized)->toBeTrue();
    tenancy()->end();
    pest()->get("http://$subdomain.localhost/central-route")->assertNotFound();

    pest()->get("http://localhost/tenant-route")->assertNotFound();
    pest()->get("http://localhost/central-route")->assertOk();
    expect(tenancy()->initialized)->toBeFalse();
})->with([
    'route-level identification, kernel access prevention' => [
        [InitializeTenancyBySubdomain::class], // Route middleware
        [PreventAccessFromUnwantedDomains::class], // Global middleware
    ],
    'kernel identification, kernel access prevention' => [
        [], // Route middleware
        [PreventAccessFromUnwantedDomains::class, InitializeTenancyBySubdomain::class], // Global middleware
    ],
    'route-level identification, route-level access prevention' => [
        [PreventAccessFromUnwantedDomains::class, InitializeTenancyBySubdomain::class], // Route middleware
        [], // Global middleware
    ],
// Creates a matrix (multiple with())
])->with([
    'central route middleware' => [['central']],
    'central route middleware with access prevention' => [['central', PreventAccessFromUnwantedDomains::class]],
]);
