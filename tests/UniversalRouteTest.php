<?php

declare(strict_types=1);

use Stancl\Tenancy\Enums\RouteMode;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Contracts\Http\Kernel;
use Stancl\Tenancy\Resolvers\PathTenantResolver;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Route as RouteFacade;
use Stancl\Tenancy\Tests\Etc\HasMiddlewareController;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;
use Stancl\Tenancy\Middleware\PreventAccessFromUnwantedDomains;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomainOrSubdomain;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedOnDomainException;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedByRequestDataException;
use function Stancl\Tenancy\Tests\pest;

test('a route can be universal using domain identification', function (array $routeMiddleware, array $globalMiddleware) {
    foreach ($globalMiddleware as $middleware) {
        // Instead of a global 'universal' MW, we use the default_route_mode config key to make routes universal by default
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

    $tenant = Tenant::create();

    $tenant->domains()->create([
        'domain' => $tenantDomain = $tenant->getTenantKey() . '.localhost',
    ]);

    pest()->get("http://localhost/foo")
        ->assertSuccessful()
        ->assertSee('Tenancy is not initialized.');

    pest()->get("http://{$tenantDomain}/foo")
        ->assertSuccessful()
        ->assertSee('Tenancy is initialized.');

    tenancy()->end();

    pest()->get("http://localhost/bar")
        ->assertSuccessful()
        ->assertSee('Tenancy is not initialized.');

    pest()->get("http://{$tenantDomain}/bar")
        ->assertSuccessful()
        ->assertSee('Tenancy is initialized.');
})->with('domain identification types');

test('a route can be universal using subdomain identification', function (array $routeMiddleware, array $globalMiddleware) {
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

    $tenant = Tenant::create();
    $tenantKey = $tenant->getTenantKey();

    $tenant->domains()->create([
        'domain' => $tenantKey,
    ]);

    pest()->get("http://localhost/foo")
        ->assertSuccessful()
        ->assertSee('Tenancy is not initialized.');

    pest()->get("http://{$tenantKey}.localhost/foo")
        ->assertSuccessful()
        ->assertSee('Tenancy is initialized.');

    tenancy()->end();

    pest()->get("http://localhost/bar")
        ->assertSuccessful()
        ->assertSee('Tenancy is not initialized.');

    pest()->get("http://{$tenantKey}.localhost/bar")
        ->assertSuccessful()
        ->assertSee('Tenancy is initialized.');
})->with('subdomain identification types');

test('a route can be universal using domainOrSubdomain identification', function (array $routeMiddleware, array $globalMiddleware) {
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

    $tenant = Tenant::create();

    $tenant->domains()->create([
        'domain' => $tenantDomain = 'tenant-domain.test',
    ]);

    $tenant->domains()->create([
        'domain' => $tenantSubdomain = 'tenant-subdomain',
    ]);

    pest()->get("http://localhost/foo")
        ->assertSuccessful()
        ->assertSee('Tenancy is not initialized.');

    // Domain identification
    pest()->get("http://{$tenantDomain}/foo")
        ->assertSuccessful()
        ->assertSee('Tenancy is initialized.');

    tenancy()->end();

    // Subdomain identification
    pest()->get("http://{$tenantSubdomain}.localhost/foo")
        ->assertSuccessful()
        ->assertSee('Tenancy is initialized.');

    tenancy()->end();

    pest()->get("http://localhost/bar")
        ->assertSuccessful()
        ->assertSee('Tenancy is not initialized.');

    pest()->get("http://{$tenantDomain}/bar")
        ->assertSuccessful()
        ->assertSee('Tenancy is initialized.');

    tenancy()->end();

    pest()->get("http://{$tenantSubdomain}.localhost/bar")
        ->assertSuccessful()
        ->assertSee('Tenancy is initialized.');
})->with('domainOrSubdomain identification types');

test('a route can be universal using request data identification', function (array $routeMiddleware, array $globalMiddleware) {
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

    $tenantKey = Tenant::create()->getTenantKey();

    pest()->get("http://localhost/foo")
        ->assertSuccessful()
        ->assertSee('Tenancy is not initialized.');

    pest()->get("http://localhost/foo?tenant={$tenantKey}")
        ->assertSuccessful()
        ->assertSee('Tenancy is initialized.');

    tenancy()->end();

    pest()->get("http://localhost/bar")
        ->assertSuccessful()
        ->assertSee('Tenancy is not initialized.');

    pest()->get("http://localhost/bar?tenant={$tenantKey}")
        ->assertSuccessful()
        ->assertSee('Tenancy is initialized.');
})->with('request data identification types');

test('correct exception is thrown when route is universal and tenant could not be identified using domain identification', function (array $routeMiddleware, array $globalMiddleware) {
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

    pest()->expectException(TenantCouldNotBeIdentifiedOnDomainException::class);
    $this->withoutExceptionHandling()->get('http://nonexistent_domain.localhost/foo');
})->with('domain identification types');

test('correct exception is thrown when route is universal and tenant could not be identified using subdomain identification', function (array $routeMiddleware, array $globalMiddleware) {
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

    pest()->expectException(TenantCouldNotBeIdentifiedOnDomainException::class);
    $this->withoutExceptionHandling()->get('http://nonexistent_subdomain.localhost/foo');
})->with('subdomain identification types');

test('correct exception is thrown when route is universal and tenant could not be identified using request data identification', function (array $routeMiddleware, array $globalMiddleware) {
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

    pest()->expectException(TenantCouldNotBeIdentifiedByRequestDataException::class);
    $this->withoutExceptionHandling()->get('http://localhost/foo?tenant=nonexistent_tenant');
})->with('request data identification types');

test('route is made universal by adding the universal flag using request data identification', function () {
    app(Kernel::class)->pushMiddleware(InitializeTenancyByRequestData::class);
    $tenant = Tenant::create();

    RouteFacade::get('/route', fn () => tenant() ? 'Tenancy initialized.' : 'Tenancy not initialized.')->middleware('universal');

    // Route is universal
    pest()->get('/route')->assertOk()->assertSee('Tenancy not initialized.');
    pest()->get('/route?tenant=' . $tenant->getTenantKey())->assertOk()->assertSee('Tenancy initialized.');
});

test('a route can be flagged as universal in both route modes', function (RouteMode $defaultRouteMode) {
    app(Kernel::class)->pushMiddleware(InitializeTenancyBySubdomain::class);
    app(Kernel::class)->pushMiddleware(PreventAccessFromUnwantedDomains::class);

    config(['tenancy.default_route_mode' => $defaultRouteMode]);

    RouteFacade::get('/universal', fn () => tenant() ? 'Tenancy is initialized.' : 'Tenancy is not initialized.')->middleware('universal');

    Tenant::create()->domains()->create(['domain' => $tenantSubdomain = 'tenant-subdomain']);

    pest()->get("http://localhost/universal")
        ->assertSuccessful()
        ->assertSee('Tenancy is not initialized.');

    pest()->get("http://{$tenantSubdomain}.localhost/universal")
        ->assertSuccessful()
        ->assertSee('Tenancy is initialized.');
})->with([
    'default to tenant routes' => RouteMode::TENANT,
    'default to central routes' => RouteMode::CENTRAL,
]);


test('tenant resolver methods return the correct names for configured values', function (string $configurableParameter, string $value) {
    $configurableParameterConfigKey = 'tenancy.identification.resolvers.' . PathTenantResolver::class . '.' . $configurableParameter;

    config([$configurableParameterConfigKey => $value]);

    // Note: The names of the methods are NOT dynamic (PathTenantResolver::tenantParameterName(), PathTenantResolver::tenantRouteNamePrefix())
    $resolverMethodName = str($configurableParameter)->camel()->toString();

    expect(PathTenantResolver::$resolverMethodName())->toBe($value);
})->with([
    ['tenant_parameter_name', 'parameter'],
    ['tenant_route_name_prefix', 'prefix']
]);

foreach ([
    'domain identification types' => [PreventAccessFromUnwantedDomains::class, InitializeTenancyByDomain::class],
    'subdomain identification types' => [PreventAccessFromUnwantedDomains::class, InitializeTenancyBySubdomain::class],
    'domainOrSubdomain identification types' => [PreventAccessFromUnwantedDomains::class, InitializeTenancyByDomainOrSubdomain::class],
    'request data identification types' => [InitializeTenancyByRequestData::class],
] as $datasetName => $middleware) {
    dataset($datasetName, [
        'kernel identification' => [
            ['universal'], // Route middleware
            $middleware, // Global middleware
        ],
        'route-level identification' => [
            ['universal', ...$middleware], // Route middleware
            [], // Global middleware
        ],
        'kernel identification + defaulting to universal routes' => [
            [], // Route middleware
            ['universal', ...$middleware], // Global middleware
        ],
        'route-level identification + defaulting to universal routes' => [
            $middleware, // Route middleware
            ['universal'], // Global middleware
        ],
    ]);
}

class Controller extends BaseController
{
    public function __invoke()
    {
        return tenant() ? 'Tenancy is initialized.' : 'Tenancy is not initialized.';
    }
}
