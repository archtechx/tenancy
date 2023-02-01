<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedOnDomainException;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromUnwantedDomains;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Contracts\Http\Kernel;

test('a route can work in both central and tenant context', function (array $routeMiddleware, string|null $globalMiddleware) {
    if ($globalMiddleware) {
        app(Kernel::class)->pushMiddleware($globalMiddleware);
    }

    Route::middlewareGroup('universal', []);

    Route::get('/foo', function () {
        return tenancy()->initialized
            ? 'Tenancy is initialized.'
            : 'Tenancy is not initialized.';
    })->middleware($routeMiddleware);

    $tenant = Tenant::create([
        'id' => 'acme',
    ]);
    $tenant->domains()->create([
        'domain' => 'acme.localhost',
    ]);

    pest()->get("http://localhost/foo")
        ->assertSuccessful()
        ->assertSee('Tenancy is not initialized.');

    pest()->get("http://acme.localhost/foo")
        ->assertSuccessful()
        ->assertSee('Tenancy is initialized.');
})->with('identification types');

test('making one route universal doesnt make all routes universal', function (array $routeMiddleware, string|null $globalMiddleware) {
    if ($globalMiddleware) {
        app(Kernel::class)->pushMiddleware($globalMiddleware);
    }

    Route::middlewareGroup('universal', []);

    Route::middleware($routeMiddleware)->group(function () {
        Route::get('/nonuniversal', function () {
            return tenant('id');
        });

        Route::get('/universal', function () {
            return tenancy()->initialized
                ? 'Tenancy is initialized.'
                : 'Tenancy is not initialized.';
        })->middleware('universal');
    });

    $tenant = Tenant::create([
        'id' => 'acme',
    ]);
    $tenant->domains()->create([
        'domain' => 'acme.localhost',
    ]);

    pest()->get("http://localhost/universal")
        ->assertSuccessful()
        ->assertSee('Tenancy is not initialized.');

    pest()->get("http://acme.localhost/universal")
        ->assertSuccessful()
        ->assertSee('Tenancy is initialized.');

    tenancy()->end();

    pest()->get('http://localhost/nonuniversal')
        ->assertStatus(404);

    pest()->get('http://acme.localhost/nonuniversal')
        ->assertSuccessful()
        ->assertSee('acme');
})->with([
    'early identification' => [
        'route_middleware' => [PreventAccessFromUnwantedDomains::class],
        'global_middleware' => InitializeTenancyByDomain::class,
    ],
    'route-level identification' => [
        'route_middleware' => [PreventAccessFromUnwantedDomains::class, InitializeTenancyByDomain::class],
        'global_middleware' => null,
    ]
]);

test('it throws correct exception when route is universal and tenant does not exist', function (array $routeMiddleware, string|null $globalMiddleware) {
    if ($globalMiddleware) {
        app(Kernel::class)->pushMiddleware($globalMiddleware);
    }

    Route::middlewareGroup('universal', []);

    Route::get('/foo', function () {
        return tenancy()->initialized
            ? 'Tenancy is initialized.'
            : 'Tenancy is not initialized.';
    })->middleware($routeMiddleware);

    pest()->expectException(TenantCouldNotBeIdentifiedOnDomainException::class);
    $this->withoutExceptionHandling()->get('http://acme.localhost/foo');
})->with('identification types');

dataset('identification types', [
    'early identification' => [
        'route_middleware' => ['universal', PreventAccessFromUnwantedDomains::class],
        'global_middleware' => InitializeTenancyByDomain::class,
    ],
    'route-level identification' => [
        'route_middleware' => ['universal', PreventAccessFromUnwantedDomains::class, InitializeTenancyByDomain::class],
        'global_middleware' => null,
    ]
]);
