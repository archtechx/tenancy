<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Tests\Etc\SingleDomainTenant;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Illuminate\Database\UniqueConstraintViolationException;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedOnDomainException;
use Stancl\Tenancy\Middleware\PreventAccessFromUnwantedDomains;
use Stancl\Tenancy\Exceptions\DomainOccupiedByOtherTenantException;
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

    // '0' is falsy, but it's a valid subdomain
    $tenant->update(['domain' => '0']);

    expect($tenant->is(app(DomainTenantResolver::class)->resolve('0')))->toBeTrue();
    DB::flushQueryLog();
    expect($tenant->is(app(DomainTenantResolver::class)->resolve('0')))->toBeTrue();
    pest()->assertEmpty(DB::getQueryLog()); // '0' is cached

    $tenant->update(['domain' => 'qux']);

    DB::flushQueryLog();
    expect(fn () => $tenant->is(app(DomainTenantResolver::class)->resolve('0')))->toThrow(TenantCouldNotBeIdentifiedOnDomainException::class);
    pest()->assertNotEmpty(DB::getQueryLog()); // '0' is not in cache anymore
});

test('tenant has to have a unique domain', function() {
    SingleDomainTenant::create(['domain' => 'bar']);

    // Checked by the EnsuresDomainIsNotOccupied trait
    expect(fn () => SingleDomainTenant::create(['domain' => 'bar']))->toThrow(DomainOccupiedByOtherTenantException::class);

    // Make the column compare case-sensitively, like it does by default on PostgreSQL and SQLite.
    // The default collation (utf8mb4_unicode_ci) would find 'bar' when checking 'BAR'
    // even if the domain wasn't lowercased before EnsuresDomainIsNotOccupied runs.
    DB::statement('ALTER TABLE tenants MODIFY domain VARCHAR(255) COLLATE utf8mb4_bin NULL');

    expect(SingleDomainTenant::where('domain', 'BAR')->exists())->toBeFalse();
    expect(SingleDomainTenant::where('domain', 'bar')->exists())->toBeTrue();

    // Throws DomainOccupiedByOtherTenantException (instead of UniqueConstraintViolationException)
    // only if ConvertsDomainsToLowercase runs before EnsuresDomainIsNotOccupied
    expect(fn () => SingleDomainTenant::create(['domain' => 'BAR']))->toThrow(DomainOccupiedByOtherTenantException::class);
    expect(SingleDomainTenant::count())->toBe(1);

    // Enforced by the unique index when model events are bypassed
    expect(fn () => DB::table('tenants')->insert(['id' => 'foo', 'domain' => 'bar', 'data' => '{}']))->toThrow(UniqueConstraintViolationException::class);
});

test('multiple tenants can be created without a domain', function () {
    $tenant1 = SingleDomainTenant::create();
    $tenant2 = SingleDomainTenant::create(['domain' => null]);

    expect($tenant1->domain)->toBeNull();
    expect($tenant2->domain)->toBeNull();

    expect(DB::table('tenants')->whereNull('domain')->count())->toBe(2);
});

test('tenant without a domain can be updated when other tenants without a domain exist', function () {
    $tenants = collect(range(1, 3))->map(fn () => SingleDomainTenant::create());

    foreach ($tenants as $tenant) {
        $tenant->update(['foo' => 'bar']);

        expect($tenant->fresh()->foo)->toBe('bar');
        expect($tenant->fresh()->domain)->toBeNull();
    }

    // A domain can be added to and removed from a tenant
    $tenants[0]->update(['domain' => 'acme']);
    expect($tenants[0]->fresh()->domain)->toBe('acme');

    $tenants[0]->update(['domain' => null]);
    expect($tenants[0]->fresh()->domain)->toBeNull();
});

test('domains are converted to lowercase when saved', function () {
    $tenant = SingleDomainTenant::create(['domain' => 'Acme.Localhost']);

    expect($tenant->fresh()->domain)->toBe('acme.localhost');

    $tenant->update(['domain' => 'FOO.localhost']);

    expect($tenant->fresh()->domain)->toBe('foo.localhost');

    // Empty domains are converted to null
    $tenant->update(['domain' => '']);

    expect($tenant->fresh()->domain)->toBeNull();
});

test('tenants list command works with single domain tenants', function () {
    $tenantWithDomain = SingleDomainTenant::create(['domain' => 'acme.localhost']);
    $tenantWithoutDomain = SingleDomainTenant::create();

    expect(Artisan::call('tenants:list'))->toBe(0);

    $output = Artisan::output();

    expect($output)->toContain('Listing 2 tenants.');
    expect($output)->toMatch("/id: {$tenantWithDomain->id} \\.+ acme\\.localhost/");
    expect($output)->toContain("id: {$tenantWithoutDomain->id}");
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
