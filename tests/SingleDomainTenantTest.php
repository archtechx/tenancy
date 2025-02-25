<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Tests\Etc\SingleDomainTenant;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Illuminate\Database\UniqueConstraintViolationException;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedOnDomainException;
use Stancl\Tenancy\Middleware\PreventAccessFromUnwantedDomains;
use function Stancl\Tenancy\Tests\pest;

beforeEach(function () {
    config(['tenancy.models.tenant' => SingleDomainTenant::class]);

    pest()->artisan('migrate', [
        '--path' => __DIR__ . '/Etc/2023_08_08_000001_add_domain_column.php',
        '--realpath' => true,
    ])->assertExitCode(0);
});

test('tenant can be resolved by its domain using the cached resolver', function () {
    $tenant = SingleDomainTenant::create(['domain' => 'acme']);
    $tenant2 = SingleDomainTenant::create(['domain' => 'bar.domain.test']);

    expect($tenant->is(app(DomainTenantResolver::class)->resolve($tenant->domain)))->toBeTrue();
    expect($tenant->is(app(DomainTenantResolver::class)->resolve($tenant2->domain)))->toBeFalse();

    expect($tenant2->is(app(DomainTenantResolver::class)->resolve($tenant2->domain)))->toBeTrue();
    expect($tenant2->is(app(DomainTenantResolver::class)->resolve($tenant->domain)))->toBeFalse();
});

test('cache is invalidated when single domain tenant is updated', function () {
    DB::enableQueryLog();

    config([
        'tenancy.models.tenant' => SingleDomainTenant::class,
        'tenancy.identification.resolvers.' . DomainTenantResolver::class . '.cache' => true
    ]);

    $tenant = SingleDomainTenant::create(['domain' => $subdomain = 'acme']);

    expect($tenant->is(app(DomainTenantResolver::class)->resolve($subdomain)))->toBeTrue();
    DB::flushQueryLog();
    expect($tenant->is(app(DomainTenantResolver::class)->resolve($subdomain)))->toBeTrue();
    expect(DB::getQueryLog())->toBeEmpty(); // empty

    $tenant->update(['foo' => 'bar']);

    DB::flushQueryLog();
    expect($tenant->is(app(DomainTenantResolver::class)->resolve($subdomain)))->toBeTrue();
    pest()->assertNotEmpty(DB::getQueryLog()); // not empty
});

test('cache is invalidated when a single domain tenants domain is updated', function () {
    DB::enableQueryLog();

    config(['tenancy.identification.resolvers.' . DomainTenantResolver::class . '.cache' => true]);

    $tenant = SingleDomainTenant::create(['domain' => 'acme']);

    expect($tenant->is(app(DomainTenantResolver::class)->resolve('acme')))->toBeTrue();
    DB::flushQueryLog();
    expect($tenant->is(app(DomainTenantResolver::class)->resolve('acme')))->toBeTrue();
    pest()->assertEmpty(DB::getQueryLog()); // Empty – tenant retrieved from cache

    $tenant->update(['domain' => 'bar']);

    DB::flushQueryLog();
    expect(fn () => $tenant->is(app(DomainTenantResolver::class)->resolve('acme')))->toThrow(TenantCouldNotBeIdentifiedOnDomainException::class);
    pest()->assertNotEmpty(DB::getQueryLog()); // resolving old subdomain (not in cache anymore)

    DB::flushQueryLog();
    expect($tenant->is(app(DomainTenantResolver::class)->resolve('bar')))->toBeTrue();
    pest()->assertNotEmpty(DB::getQueryLog()); // resolving using new subdomain for the first time

    DB::flushQueryLog();
    expect($tenant->is(app(DomainTenantResolver::class)->resolve('bar')))->toBeTrue();
    pest()->assertEmpty(DB::getQueryLog()); // resolving using new subdomain for the second time

    $tenant->update(['domain' => 'baz']);

    DB::flushQueryLog();
    expect(fn () => $tenant->is(app(DomainTenantResolver::class)->resolve('acme')))->toThrow(TenantCouldNotBeIdentifiedOnDomainException::class);
    pest()->assertNotEmpty(DB::getQueryLog()); // resolving using first old subdomain - no cache + failed

    DB::flushQueryLog();
    expect(fn () => $tenant->is(app(DomainTenantResolver::class)->resolve('bar')))->toThrow(TenantCouldNotBeIdentifiedOnDomainException::class);
    pest()->assertNotEmpty(DB::getQueryLog()); // resolving using second old subdomain - no cache + failed

    DB::flushQueryLog();
    expect($tenant->is(app(DomainTenantResolver::class)->resolve('baz')))->toBeTrue();
    pest()->assertNotEmpty(DB::getQueryLog()); // resolving using current subdomain for the first time

    DB::flushQueryLog();
    expect($tenant->is(app(DomainTenantResolver::class)->resolve('baz')))->toBeTrue();
    pest()->assertEmpty(DB::getQueryLog()); // resolving using current subdomain for the second time
});

test('tenant has to have a unique domain', function() {
    SingleDomainTenant::create(['domain' => 'bar']);

    expect(fn () => SingleDomainTenant::create(['domain' => 'bar']))->toThrow(UniqueConstraintViolationException::class);
});

test('single domain tenant can be identified by domain or subdomain', function (string $domain, array $identificationMiddleware) {
    $tenant = SingleDomainTenant::create(['domain' => $domain]);

    Route::get('/foo/{a}/{b}', function ($a, $b) {
        return "$a + $b";
    })->middleware($identificationMiddleware);

    if ($domain === 'acme') {
        $domain .= '.localhost';
    }

    pest()
        ->get("http://{$domain}/foo/abc/xyz")
        ->assertSee('abc + xyz');

    expect(tenant('id'))->toBe($tenant->id);
})->with([
    [
        'acme.localhost', // Domain
        [PreventAccessFromUnwantedDomains::class, InitializeTenancyByDomain::class], // Identification middleware
    ],
    [
        'acme', // Subdomain
        [PreventAccessFromUnwantedDomains::class, InitializeTenancyBySubdomain::class], // Identification middleware
    ],
]);
