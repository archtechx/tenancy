<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomainOrSubdomain;
use Stancl\Tenancy\Tests\Etc\Tenant;
use function Stancl\Tenancy\Tests\pest;

beforeEach(function () {
    Route::group([
        'middleware' => InitializeTenancyByDomainOrSubdomain::class,
    ], function () {
        Route::get('/test', function () {
            return tenant('id');
        });
    });
});

test('tenant can be identified by subdomain', function () {
    config(['tenancy.identification.central_domains' => ['localhost']]);

    $tenant = Tenant::create(['id' => 'acme']);
    $tenant->domains()->create(['domain' => 'foo']);

    expect(tenancy()->initialized)->toBeFalse();

    pest()->get('http://foo.localhost/test')->assertSee('acme');
});

test('tenant can be identified by domain', function () {
    config(['tenancy.identification.central_domains' => []]);

    $tenant = Tenant::create(['id' => 'acme']);
    $tenant->domains()->create(['domain' => 'foobar.localhost']);

    expect(tenancy()->initialized)->toBeFalse();

    pest()->get('http://foobar.localhost/test')->assertSee('acme');
});

test('domain records can be either in domain syntax or subdomain syntax', function () {
    config(['tenancy.identification.central_domains' => ['localhost']]);

    $foo = Tenant::create(['id' => 'foo']);
    $foo->domains()->create(['domain' => 'foo']);

    $bar = Tenant::create(['id' => 'bar']);
    $bar->domains()->create(['domain' => 'bar.localhost']);

    expect(tenancy()->initialized)->toBeFalse();

    // Subdomain format
    pest()->get('http://foo.localhost/test')->assertSee('foo');

    tenancy()->end();

    // Domain format
    pest()->get('http://bar.localhost/test')->assertSee('bar');
});
