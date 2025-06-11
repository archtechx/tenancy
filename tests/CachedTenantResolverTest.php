<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Stancl\Tenancy\Resolvers\PathTenantResolver;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedOnDomainException;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;
use Stancl\Tenancy\Resolvers\RequestDataTenantResolver;
use function Stancl\Tenancy\Tests\pest;

test('tenants can be resolved using cached resolvers', function (string $resolver, bool $configureTenantModelColumn) {
    $tenant = Tenant::create([$tenantModelColumn = tenantModelColumn($configureTenantModelColumn) => 'acme']);

    $tenant->createDomain($tenant->{$tenantModelColumn});

    expect($tenant->is(app($resolver)->resolve(getResolverArgument($resolver, $tenant, $tenantModelColumn))))->toBeTrue();
})->with([
    DomainTenantResolver::class,
    PathTenantResolver::class,
    RequestDataTenantResolver::class,
])->with([
    'tenant column is id (default)' => false,
    'tenant column is name (custom)' => true,
]);

test('the underlying resolver is not touched when using the cached resolver', function (string $resolver, bool $configureTenantModelColumn) {
    $tenant = Tenant::create([$tenantModelColumn = tenantModelColumn($configureTenantModelColumn) => 'acme']);

    $tenant->createDomain($tenant->{$tenantModelColumn});

    DB::enableQueryLog();

    config(['tenancy.identification.resolvers.' . $resolver . '.cache' => false]);

    expect($tenant->is(app($resolver)->resolve(getResolverArgument($resolver, $tenant, $tenantModelColumn))))->toBeTrue();
    DB::flushQueryLog();
    expect($tenant->is(app($resolver)->resolve(getResolverArgument($resolver, $tenant, $tenantModelColumn))))->toBeTrue();

    pest()->assertNotEmpty(DB::getQueryLog()); // not empty

    config(['tenancy.identification.resolvers.' . $resolver . '.cache' => true]);

    expect($tenant->is(app($resolver)->resolve(getResolverArgument($resolver, $tenant, $tenantModelColumn))))->toBeTrue();
    DB::flushQueryLog();
    expect($tenant->is(app($resolver)->resolve(getResolverArgument($resolver, $tenant, $tenantModelColumn))))->toBeTrue();
    expect(DB::getQueryLog())->toBeEmpty(); // empty
})->with([
    DomainTenantResolver::class,
    PathTenantResolver::class,
    RequestDataTenantResolver::class,
])->with([
    'tenant column is id (default)' => false,
    'tenant column is name (custom)' => true,
]);

test('cache is invalidated when the tenant is updated', function (string $resolver, bool $configureTenantModelColumn) {
    $tenant = Tenant::create([$tenantModelColumn = tenantModelColumn($configureTenantModelColumn) => 'acme']);

    $tenant->createDomain($tenant->{$tenantModelColumn});

    DB::enableQueryLog();

    config(['tenancy.identification.resolvers.' . $resolver . '.cache' => true]);

    expect($tenant->is(app($resolver)->resolve(getResolverArgument($resolver, $tenant, $tenantModelColumn))))->toBeTrue();
    expect(DB::getQueryLog())->not()->toBeEmpty();

    DB::flushQueryLog();

    expect($tenant->is(app($resolver)->resolve(getResolverArgument($resolver, $tenant, $tenantModelColumn))))->toBeTrue();
    expect(DB::getQueryLog())->toBeEmpty();

    // Tenant cache gets invalidated when the tenant is updated
    $tenant->touch();

    DB::flushQueryLog();

    expect($tenant->is(app($resolver)->resolve(getResolverArgument($resolver, $tenant, $tenantModelColumn))))->toBeTrue();

    expect(DB::getQueryLog())->not()->toBeEmpty(); // Cache was invalidated, so the tenant was retrieved from the DB
})->with([
    DomainTenantResolver::class,
    PathTenantResolver::class,
    RequestDataTenantResolver::class,
])->with([
    'tenant column is id (default)' => false,
    'tenant column is name (custom)' => true,
]);

test('cache is invalidated when the tenant is deleted', function (string $resolver, bool $configureTenantModelColumn) {
    DB::statement('SET FOREIGN_KEY_CHECKS=0;'); // allow deleting the tenant
    $tenant = Tenant::create([$tenantModelColumn = tenantModelColumn($configureTenantModelColumn) => 'acme']);
    $tenant->createDomain($tenant->{$tenantModelColumn});

    DB::enableQueryLog();

    config(['tenancy.identification.resolvers.' . $resolver . '.cache' => true]);

    expect($tenant->is(app($resolver)->resolve(getResolverArgument($resolver, $tenant, $tenantModelColumn))))->toBeTrue();
    expect(DB::getQueryLog())->not()->toBeEmpty();

    DB::flushQueryLog();

    expect($tenant->is(app($resolver)->resolve(getResolverArgument($resolver, $tenant, $tenantModelColumn))))->toBeTrue();
    expect(DB::getQueryLog())->toBeEmpty();

    $tenant->delete();
    DB::flushQueryLog();

    expect(fn () => app($resolver)->resolve(getResolverArgument($resolver, $tenant, $tenantModelColumn)))->toThrow(TenantCouldNotBeIdentifiedException::class);
    expect(DB::getQueryLog())->not()->toBeEmpty(); // Cache was invalidated, so the DB was queried
})->with([
    DomainTenantResolver::class,
    PathTenantResolver::class,
    RequestDataTenantResolver::class,
])->with([
    'tenant column is id (default)' => false,
    'tenant column is name (custom)' => true,
]);

test('cache is invalidated when a tenants domain is changed', function () {
    $tenant = Tenant::create(['id' => $tenantKey = 'acme']);
    $tenant->createDomain($tenantKey);

    DB::enableQueryLog();

    config(['tenancy.identification.resolvers.' . DomainTenantResolver::class . '.cache' => true]);

    expect($tenant->is(app(DomainTenantResolver::class)->resolve('acme')))->toBeTrue();
    DB::flushQueryLog();
    expect($tenant->is(app(DomainTenantResolver::class)->resolve('acme')))->toBeTrue();
    expect(DB::getQueryLog())->toBeEmpty(); // empty

    $tenant->createDomain([
        'domain' => 'bar',
    ]);

    DB::flushQueryLog();
    expect($tenant->is(app(DomainTenantResolver::class)->resolve('acme')))->toBeTrue();
    pest()->assertNotEmpty(DB::getQueryLog()); // not empty

    DB::flushQueryLog();
    expect($tenant->is(app(DomainTenantResolver::class)->resolve('bar')))->toBeTrue();
    pest()->assertNotEmpty(DB::getQueryLog()); // not empty
});

test('cache is invalidated when a tenants domain is deleted', function () {
    $tenant = Tenant::create(['id' => $tenantKey = 'acme']);
    $tenant->createDomain($tenantKey);

    DB::enableQueryLog();

    config(['tenancy.identification.resolvers.' . DomainTenantResolver::class . '.cache' => true]);

    expect($tenant->is(app(DomainTenantResolver::class)->resolve('acme')))->toBeTrue();
    DB::flushQueryLog();
    expect($tenant->is(app(DomainTenantResolver::class)->resolve('acme')))->toBeTrue();
    expect(DB::getQueryLog())->toBeEmpty(); // empty

    $tenant->domains->first()->delete();
    DB::flushQueryLog();

    expect(fn () => $tenant->is(app(DomainTenantResolver::class)->resolve('acme')))->toThrow(TenantCouldNotBeIdentifiedOnDomainException::class);
    expect(DB::getQueryLog())->not()->toBeEmpty(); // Cache was invalidated, so the DB was queried
});

test('PathTenantResolver forgets the tenant route parameter when the tenant is resolved from cache', function() {
    config(['tenancy.identification.resolvers.' . PathTenantResolver::class . '.cache' => true]);
    DB::enableQueryLog();

    Tenant::create(['id' => 'foo']);

    RouteFacade::get('/', fn () => request()->route()->parameter('tenant') ? 'Tenant parameter present' : 'No tenant parameter')
        ->name('tenant-route')
        ->prefix('{tenant}')
        ->middleware(InitializeTenancyByPath::class);

    // Tenant gets cached on first request
    pest()->get("/foo")->assertSee('No tenant parameter');

    // Tenant is resolved from cache on second request
    // The tenant parameter should be forgotten
    DB::flushQueryLog();
    pest()->get("/foo")->assertSee('No tenant parameter');
    pest()->assertEmpty(DB::getQueryLog()); // resolved from cache
});

test('PathTenantResolver properly separates cache for each tenant column', function () {
    config(['tenancy.identification.resolvers.' . PathTenantResolver::class . '.cache' => true]);
    config(['tenancy.identification.resolvers.' . PathTenantResolver::class . '.allowed_extra_model_columns' => ['slug']]);
    Tenant::$extraCustomColumns = ['slug'];
    DB::enableQueryLog();

    Schema::table('tenants', function (Blueprint $table) {
        $table->string('slug')->unique();
    });

    $t1 = Tenant::create(['id' => 'foo', 'slug' => 'bar']);
    $t2 = Tenant::create(['id' => 'bar', 'slug' => 'foo']);

    RouteFacade::get('x/{tenant}/a', function () {
        return tenant()->getTenantKey();
    })->middleware(InitializeTenancyByPath::class);

    RouteFacade::get('x/{tenant:slug}/b', function () {
        return tenant()->getTenantKey();
    })->middleware(InitializeTenancyByPath::class);

    DB::flushQueryLog();

    $redisKeys = fn () => array_map(
        fn (string $key) => str($key)->after('PathTenantResolver:')->toString(),
        Redis::connection('cache')->keys('*')
    );

    pest()->get("/x/foo/a")->assertSee('foo');
    expect(count(DB::getRawQueryLog()))->toBe(1);
    expect(DB::getRawQueryLog()[0]['raw_query'])->toBe("select * from `tenants` where `id` = 'foo' limit 1");
    expect($redisKeys())->toEqualCanonicalizing([
        '["id","foo"]',
    ]);

    pest()->get("/x/bar/b")->assertSee('foo');
    expect(count(DB::getRawQueryLog()))->toBe(2);
    expect(DB::getRawQueryLog()[1]['raw_query'])->toBe("select * from `tenants` where `slug` = 'bar' limit 1");
    expect($redisKeys())->toEqualCanonicalizing([
        '["id","foo"]',
        '["slug","bar"]',
    ]);

    // Test if cache hits
    pest()->get("/x/foo/a")->assertSee('foo');
    expect(count(DB::getRawQueryLog()))->toBe(2); // unchanged
    expect(count($redisKeys()))->toBe(2); // unchanged

    pest()->get("/x/bar/b")->assertSee('foo');
    expect(count(DB::getRawQueryLog()))->toBe(2); // unchanged
    expect(count($redisKeys()))->toBe(2); // unchanged

    // Make requests for a tenant that has reversed values for the columns
    pest()->get("/x/bar/a")->assertSee('bar');
    expect(count(DB::getRawQueryLog()))->toBe(3); // +1
    expect(DB::getRawQueryLog()[2]['raw_query'])->toBe("select * from `tenants` where `id` = 'bar' limit 1");
    expect($redisKeys())->toEqualCanonicalizing([
        '["id","foo"]',
        '["slug","bar"]',
        '["id","bar"]', // added
    ]);

    pest()->get("/x/foo/b")->assertSee('bar');
    expect(count(DB::getRawQueryLog()))->toBe(4);
    expect(DB::getRawQueryLog()[3]['raw_query'])->toBe("select * from `tenants` where `slug` = 'foo' limit 1");
    expect($redisKeys())->toEqualCanonicalizing([
        '["id","foo"]',
        '["slug","bar"]',
        '["id","bar"]',
        '["slug","foo"]', // added
    ]);

    // Test if cache hits for the tenant with reversed values
    pest()->get("/x/bar/a")->assertSee('bar');
    expect(count(DB::getRawQueryLog()))->toBe(4); // unchanged
    expect(count($redisKeys()))->toBe(4); // unchanged

    pest()->get("/x/foo/b")->assertSee('bar');
    expect(count(DB::getRawQueryLog()))->toBe(4); // unchanged
    expect(count($redisKeys()))->toBe(4); // unchanged

    // Try to resolve the previous tenant again, confirming the cache values for the new tenant are properly separated from the previous tenant
    pest()->get("/x/foo/a")->assertSee('foo');
    pest()->get("/x/foo/b")->assertSee('bar');
    pest()->get("/x/bar/a")->assertSee('bar');
    pest()->get("/x/bar/b")->assertSee('foo');
    expect(count(DB::getRawQueryLog()))->toBe(4); // unchanged
    expect(count($redisKeys()))->toBe(4); // unchanged

    $t1->update(['random_value' => 'just to clear cache']);
    expect($redisKeys())->toEqualCanonicalizing([
        // '["id","foo"]', // these two have been removed
        // '["slug","bar"]',
        '["id","bar"]',
        '["slug","foo"]',
    ]);

    $t2->update(['random_value' => 'just to clear cache']);
    expect($redisKeys())->toBe([]);

    DB::flushQueryLog();

    // Cache gets repopulated
    pest()->get("/x/foo/a")->assertSee('foo');
    expect(count(DB::getRawQueryLog()))->toBe(1);
    expect(count($redisKeys()))->toBe(1);

    pest()->get("/x/foo/b")->assertSee('bar');
    expect(count(DB::getRawQueryLog()))->toBe(2);
    expect(count($redisKeys()))->toBe(2);

    pest()->get("/x/bar/a")->assertSee('bar');
    expect(count(DB::getRawQueryLog()))->toBe(3);
    expect(count($redisKeys()))->toBe(3);

    pest()->get("/x/bar/b")->assertSee('foo');
    expect(count(DB::getRawQueryLog()))->toBe(4);
    expect(count($redisKeys()))->toBe(4);

    // After which, the cache becomes active again
    pest()->get("/x/foo/a")->assertSee('foo');
    pest()->get("/x/foo/b")->assertSee('bar');
    pest()->get("/x/bar/a")->assertSee('bar');
    pest()->get("/x/bar/b")->assertSee('foo');
    expect(count(DB::getRawQueryLog()))->toBe(4); // unchanged
    expect(count($redisKeys()))->toBe(4); // unchanged

    Tenant::$extraCustomColumns = []; // reset
});

/**
 * This method is used in generic tests to ensure that caching works correctly both with default and custom resolver config.
 *
 * If $configureTenantModelColumn is false, the tenant model column is 'id' (default) -- don't configure anything, keep the defaults.
 * If $configureTenantModelColumn is true, the tenant model column should be 'name' (custom) -- configure tenant_model_column in the resolvers.
 */
function tenantModelColumn(bool $configureTenantModelColumn): string {
    // Default tenant model column is 'id'
    $tenantModelColumn = 'id';

    if ($configureTenantModelColumn) {
        // Use 'name' as the custom tenant model column
        $tenantModelColumn = 'name';

        Tenant::$extraCustomColumns = [$tenantModelColumn];

        Schema::table('tenants', function (Blueprint $table) use ($tenantModelColumn) {
            $table->string($tenantModelColumn)->unique();
        });

        config(['tenancy.identification.resolvers.' . PathTenantResolver::class . '.tenant_model_column' => $tenantModelColumn]);
        config(['tenancy.identification.resolvers.' . RequestDataTenantResolver::class . '.tenant_model_column' => $tenantModelColumn]);
    }

    return $tenantModelColumn;
}

/**
 * This method is used in generic tests where we test all the resolvers
 * to make getting the resolver arguments less repetitive (primarily because of PathTenantResolver).
 *
 * For PathTenantResolver, return a route instance with the value retrieved using $tenant->{$parameterColumn} as the parameter.
 * For RequestDataTenantResolver and DomainTenantResolver, return the value retrieved using $tenant->{$parameterColumn}.
 *
 * Tenant column name is 'id' by default, but in the generic tests,
 * we also configure that to 'name' to ensure everything works both with default and custom config.
 */
function getResolverArgument(string $resolver, Tenant $tenant, string $parameterColumn = 'id'): string|Route
{
    if ($resolver === PathTenantResolver::class) {
        // PathTenantResolver uses a route instance as the argument
        $routeName = 'tenant-route';

        // Find or create a route instance for the resolver
        $route = RouteFacade::getRoutes()->getByName($routeName) ?? RouteFacade::get('/', fn () => null)
            ->name($routeName)
            ->prefix('{tenant}')
            ->middleware(InitializeTenancyByPath::class);

        /**
         * To make the tenant available on the route instance,
         * set the 'tenant' route parameter to the tenant model column value ('id' or 'name').
         *
         * Setting the parameter on the $route->parameters property is required
         * because $route->setParameter() throws an exception when $route->parameters isn't set yet.
         */
        $route->parameters['tenant'] = $tenant->{$parameterColumn};

        // Return the route instance with 'id' or 'name' as the 'tenant' parameter
        return $route;
    }

    // Assuming that:
    // - with RequestDataTenantResolver, the tenant model column value is the payload value
    // - with DomainTenantResolver, the tenant has a domain with name equal to the tenant model column value
    return $tenant->{$parameterColumn};
}
