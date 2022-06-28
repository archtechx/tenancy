<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models;
use Stancl\Tenancy\Database\Models\Domain;
use Stancl\Tenancy\Exceptions\DomainOccupiedByOtherTenantException;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedOnDomainException;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;

uses(Stancl\Tenancy\Tests\TestCase::class);

beforeEach(function () {
    Route::group([
        'middleware' => InitializeTenancyByDomain::class,
    ], function () {
        Route::get('/foo/{a}/{b}', function ($a, $b) {
            return "$a + $b";
        });
    });

    config(['tenancy.tenant_model' => DomainTenant::class]);
});

test('tenant can be identified using hostname', function () {
    $tenant = DomainTenant::create();

    $id = $tenant->id;

    $tenant->domains()->create([
        'domain' => 'foo.localhost',
    ]);

    $resolvedTenant = app(DomainTenantResolver::class)->resolve('foo.localhost');

    $this->assertSame($id, $resolvedTenant->id);
    $this->assertSame(['foo.localhost'], $resolvedTenant->domains->pluck('domain')->toArray());
});

test('a domain can belong to only one tenant', function () {
    $tenant = DomainTenant::create();

    $tenant->domains()->create([
        'domain' => 'foo.localhost',
    ]);

    $tenant2 = DomainTenant::create();

    $this->expectException(DomainOccupiedByOtherTenantException::class);
    $tenant2->domains()->create([
        'domain' => 'foo.localhost',
    ]);
});

test('an exception is thrown if tenant cannot be identified', function () {
    $this->expectException(TenantCouldNotBeIdentifiedOnDomainException::class);

    app(DomainTenantResolver::class)->resolve('foo.localhost');
});

test('tenant can be identified by domain', function () {
    $tenant = DomainTenant::create([
        'id' => 'acme',
    ]);

    $tenant->domains()->create([
        'domain' => 'foo.localhost',
    ]);

    $this->assertFalse(tenancy()->initialized);

    $this
        ->get('http://foo.localhost/foo/abc/xyz')
        ->assertSee('abc + xyz');

    $this->assertTrue(tenancy()->initialized);
    $this->assertSame('acme', tenant('id'));
});

test('onfail logic can be customized', function () {
    InitializeTenancyByDomain::$onFail = function () {
        return 'foo';
    };

    $this
        ->get('http://foo.localhost/foo/abc/xyz')
        ->assertSee('foo');
});

test('domains are always lowercase', function () {
    $tenant = DomainTenant::create();

    $tenant->domains()->create([
        'domain' => 'CAPITALS',
    ]);

    $this->assertSame('capitals', Domain::first()->domain);
});
