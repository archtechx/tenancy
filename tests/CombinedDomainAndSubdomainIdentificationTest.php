<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomainOrSubdomain;
use Stancl\Tenancy\Database\Models;

beforeEach(function () {
    Route::group([
        'middleware' => InitializeTenancyByDomainOrSubdomain::class,
    ], function () {
        Route::get('/foo/{a}/{b}', function ($a, $b) {
            return "$a + $b";
        });
    });

    config(['tenancy.tenant_model' => CombinedTenant::class]);
});

test('tenant can be identified by subdomain', function () {
    config(['tenancy.central_domains' => ['localhost']]);

    $tenant = CombinedTenant::create([
        'id' => 'acme',
    ]);

    $tenant->domains()->create([
        'domain' => 'foo',
    ]);

    expect(tenancy()->initialized)->toBeFalse();

    pest()
        ->get('http://foo.localhost/foo/abc/xyz')
        ->assertSee('abc + xyz');

    expect(tenancy()->initialized)->toBeTrue();
    expect(tenant('id'))->toBe('acme');
});

test('tenant can be identified by domain', function () {
    config(['tenancy.central_domains' => []]);

    $tenant = CombinedTenant::create([
        'id' => 'acme',
    ]);

    $tenant->domains()->create([
        'domain' => 'foobar.localhost',
    ]);

    expect(tenancy()->initialized)->toBeFalse();

    pest()
        ->get('http://foobar.localhost/foo/abc/xyz')
        ->assertSee('abc + xyz');

    expect(tenancy()->initialized)->toBeTrue();
    expect(tenant('id'))->toBe('acme');
});

class CombinedTenant extends Models\Tenant
{
    use HasDomains;
}
