<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Features\UniversalRoutes;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Tests\Etc\Tenant;

afterEach(function () {
    InitializeTenancyByDomain::$onFail = null;
});

test('a route can work in both central and tenant context', function () {
    Route::middlewareGroup('universal', []);
    config(['tenancy.features' => [UniversalRoutes::class]]);

    Route::get('/foo', function () {
        return tenancy()->initialized
            ? 'Tenancy is initialized.'
            : 'Tenancy is not initialized.';
    })->middleware(['universal', InitializeTenancyByDomain::class]);

    pest()->get('http://localhost/foo')
        ->assertSuccessful()
        ->assertSee('Tenancy is not initialized.');

    $tenant = Tenant::create([
        'id' => 'acme',
    ]);
    $tenant->domains()->create([
        'domain' => 'acme.localhost',
    ]);

    pest()->get('http://acme.localhost/foo')
        ->assertSuccessful()
        ->assertSee('Tenancy is initialized.');
});

test('making one route universal doesnt make all routes universal', function () {
    Route::get('/bar', function () {
        return tenant('id');
    })->middleware(InitializeTenancyByDomain::class);

    Route::middlewareGroup('universal', []);
    config(['tenancy.features' => [UniversalRoutes::class]]);

    Route::get('/foo', function () {
        return tenancy()->initialized
            ? 'Tenancy is initialized.'
            : 'Tenancy is not initialized.';
    })->middleware(['universal', InitializeTenancyByDomain::class]);

    pest()->get('http://localhost/foo')
        ->assertSuccessful()
        ->assertSee('Tenancy is not initialized.');

    $tenant = Tenant::create([
        'id' => 'acme',
    ]);
    $tenant->domains()->create([
        'domain' => 'acme.localhost',
    ]);

    pest()->get('http://acme.localhost/foo')
        ->assertSuccessful()
        ->assertSee('Tenancy is initialized.');

    tenancy()->end();

    pest()->get('http://localhost/bar')
        ->assertStatus(500);

    pest()->get('http://acme.localhost/bar')
        ->assertSuccessful()
        ->assertSee('acme');
});
