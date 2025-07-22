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
use function Stancl\Tenancy\Tests\pest;

beforeEach(function () {
    InitializeTenancyByDomain::$onFail = null;

    Route::group([
        'middleware' => InitializeTenancyByDomain::class,
    ], function () {
        Route::get('/foo/{a}/{b}', function ($a, $b) {
            return "$a + $b";
        });
    });

    config(['tenancy.models.tenant' => DomainTenant::class]);
});

afterEach(function () {
    InitializeTenancyByDomain::$onFail = null;
});

test('tenant can be identified using hostname', function () {
    $tenant = DomainTenant::create();

    $id = $tenant->id;

    $tenant->domains()->create([
        'domain' => 'foo.localhost',
    ]);

    $resolvedTenant = app(DomainTenantResolver::class)->resolve('foo.localhost');

    expect($resolvedTenant->id)->toBe($id);
    expect($resolvedTenant->domains->pluck('domain')->toArray())->toBe(['foo.localhost']);
});

test('a domain can belong to only one tenant', function () {
    $tenant = DomainTenant::create();

    $tenant->domains()->create([
        'domain' => 'foo.localhost',
    ]);

    $tenant2 = DomainTenant::create();

    pest()->expectException(DomainOccupiedByOtherTenantException::class);
    $tenant2->domains()->create([
        'domain' => 'foo.localhost',
    ]);
});

test('an exception is thrown if tenant cannot be identified', function () {
    pest()->expectException(TenantCouldNotBeIdentifiedOnDomainException::class);

    app(DomainTenantResolver::class)->resolve('foo.localhost');
});

test('tenant can be identified by domain', function () {
    $tenant = DomainTenant::create([
        'id' => 'acme',
    ]);

    $tenant->domains()->create([
        'domain' => 'foo.localhost',
    ]);

    expect(tenancy()->initialized)->toBeFalse();

    pest()
        ->get('http://foo.localhost/foo/abc/xyz')
        ->assertSee('abc + xyz');

    expect(tenancy()->initialized)->toBeTrue();
    expect(tenant('id'))->toBe('acme');
});

test('onfail logic can be customized', function () {
    InitializeTenancyByDomain::$onFail = function () {
        return response('foo');
    };

    pest()
        ->get('http://foo.localhost/foo/abc/xyz')
        ->assertSee('foo');
});

test('throw correct exception when onFail is null and universal routes are enabled', function () {
    // Enable UniversalRoute feature
    Route::middlewareGroup('universal', []);

    $this->withoutExceptionHandling()->get('http://foo.localhost/foo/abc/xyz');
})->throws(TenantCouldNotBeIdentifiedOnDomainException::class);;

test('domains are always lowercase', function () {
    $tenant = DomainTenant::create();

    $tenant->domains()->create([
        'domain' => 'CAPITALS',
    ]);

    expect(Domain::first()->domain)->toBe('capitals');
});

class DomainTenant extends Models\Tenant
{
    use HasDomains;
}
