<?php

declare(strict_types=1);

use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Facades\GlobalCache;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Bootstrappers\CacheTagsBootstrapper;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseCacheBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;

use function Stancl\Tenancy\Tests\withCacheTables;
use function Stancl\Tenancy\Tests\withTenantDatabases;

beforeEach(function () {
    config([
        'cache.default' => 'redis',
        'tenancy.cache.stores' => ['redis'],
    ]);

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);

    withCacheTables();
});

test('global cache manager stores data in global cache', function (string $store, array $bootstrappers) {
    config([
        'cache.default' => $store,
        'tenancy.bootstrappers' => $bootstrappers,
    ]);

    if ($store === 'database') withTenantDatabases(true);

    expect(cache('foo'))->toBe(null);
    GlobalCache::put('foo', 'bar');
    expect(GlobalCache::get('foo'))->toBe('bar');

    $tenant1 = Tenant::create();
    tenancy()->initialize($tenant1);
    expect(GlobalCache::get('foo'))->toBe('bar');

    GlobalCache::put('abc', 'xyz');
    cache(['def' => 'ghi'], 10);
    expect(cache('def'))->toBe('ghi');

    // different stores
    expect(cache()->store()->getStore() !== GlobalCache::store()->getStore())->toBeTrue();
    if ($store === 'redis') {
        // same underlying connection. the prefix is set ON THE STORE
        expect(cache()->store()->getStore()->connection() === GlobalCache::store()->getStore()->connection())->toBeTrue();
    } else {
        // different connections
        expect(cache()->store()->getStore()->getConnection()->getName())->toBe('tenant');
        expect(GlobalCache::store()->getStore()->getConnection()->getName())->toBe('central');
    }

    tenancy()->end();
    expect(GlobalCache::get('abc'))->toBe('xyz');
    expect(GlobalCache::get('foo'))->toBe('bar');
    expect(cache('def'))->toBe(null);

    $tenant2 = Tenant::create();
    tenancy()->initialize($tenant2);
    expect(GlobalCache::get('abc'))->toBe('xyz');
    expect(GlobalCache::get('foo'))->toBe('bar');
    expect(cache('def'))->toBe(null);
    cache(['def' => 'xxx'], 1);
    expect(cache('def'))->toBe('xxx');

    tenancy()->initialize($tenant1);
    expect(cache('def'))->toBe('ghi');
})->with([
    ['redis', [CacheTagsBootstrapper::class]],
    ['redis', [CacheTenancyBootstrapper::class]],
    ['database', [DatabaseTenancyBootstrapper::class, DatabaseCacheBootstrapper::class]],
]);

test('global cache facade is not persistent', function () {
    $oldId = spl_object_id(GlobalCache::getFacadeRoot());

    $_ = new class {};

    expect(spl_object_id(GlobalCache::getFacadeRoot()))->not()->toBe($oldId);
});

test('global cache is always central', function (string $store, array $bootstrappers, string $initialCentralCall) {
    config([
        'cache.default' => $store,
        'tenancy.bootstrappers' => $bootstrappers,
    ]);

    if ($store === 'database') {
        withTenantDatabases(true);
    }

    // This tells us which "accessor" for the global cache should be instantiated first, before we go
    // into the tenant context. We make sure to not touch the other one here. This tests that whether
    // a particular accessor is used "early" makes no difference in the later behavior.
    if ($initialCentralCall === 'helper') {
        if ($store === 'database') expect(global_cache()->store()->getStore()->getConnection()->getName())->toBe('central');
        global_cache()->put('central-helper', true);
    } else if ($initialCentralCall === 'facade') {
        if ($store === 'database') expect(GlobalCache::store()->getStore()->getConnection()->getName())->toBe('central');
        GlobalCache::put('central-facade', true);
    } else if ($initialCentralCall === 'both') {
        if ($store === 'database') expect(global_cache()->store()->getStore()->getConnection()->getName())->toBe('central');
        global_cache()->put('central-helper', true);
        if ($store === 'database') expect(GlobalCache::store()->getStore()->getConnection()->getName())->toBe('central');
        GlobalCache::put('central-facade', true);
    }

    $tenant = Tenant::create();
    $tenant->enter();

    // Here we use both the helper and the facade to ensure the value is accessible via either one
    if ($initialCentralCall === 'helper') {
        if ($store === 'database') expect(global_cache()->store()->getStore()->getConnection()->getName())->toBe('central');
        if ($store === 'database') expect(GlobalCache::store()->getStore()->getConnection()->getName())->toBe('central');
        expect(global_cache('central-helper'))->toBe(true);
        expect(GlobalCache::get('central-helper'))->toBe(true);
    } else if ($initialCentralCall === 'facade') {
        if ($store === 'database') expect(global_cache()->store()->getStore()->getConnection()->getName())->toBe('central');
        if ($store === 'database') expect(GlobalCache::store()->getStore()->getConnection()->getName())->toBe('central');
        expect(global_cache('central-facade'))->toBe(true);
        expect(GlobalCache::get('central-facade'))->toBe(true);
    } else if ($initialCentralCall === 'both') {
        if ($store === 'database') expect(global_cache()->store()->getStore()->getConnection()->getName())->toBe('central');
        if ($store === 'database') expect(GlobalCache::store()->getStore()->getConnection()->getName())->toBe('central');
        expect(global_cache('central-helper'))->toBe(true);
        expect(GlobalCache::get('central-helper'))->toBe(true);
        expect(global_cache('central-facade'))->toBe(true);
        expect(GlobalCache::get('central-facade'))->toBe(true);
    }

    global_cache()->put('tenant-helper', true);
    GlobalCache::put('tenant-facade', true);

    tenancy()->end();

    if ($store === 'database') expect(global_cache()->store()->getStore()->getConnection()->getName())->toBe('central');
    if ($store === 'database') expect(GlobalCache::store()->getStore()->getConnection()->getName())->toBe('central');

    expect(global_cache('tenant-helper'))->toBe(true);
    expect(GlobalCache::get('tenant-helper'))->toBe(true);
    expect(global_cache('tenant-facade'))->toBe(true);
    expect(GlobalCache::get('tenant-facade'))->toBe(true);

    if ($initialCentralCall === 'helper') {
        expect(GlobalCache::get('central-helper'))->toBe(true);
    } else if ($initialCentralCall === 'facade') {
        expect(global_cache('central-facade'))->toBe(true);
    } else if ($initialCentralCall === 'both') {
        expect(global_cache('central-helper'))->toBe(true);
        expect(GlobalCache::get('central-helper'))->toBe(true);
        expect(global_cache('central-facade'))->toBe(true);
        expect(GlobalCache::get('central-facade'))->toBe(true);
    }
})->with([
    ['redis', [CacheTagsBootstrapper::class]],
    ['redis', [CacheTenancyBootstrapper::class]],
    ['database', [DatabaseTenancyBootstrapper::class, DatabaseCacheBootstrapper::class]],
])->with([
    'helper',
    'facade',
    'both',
    'none',
]);

test('the global_cache helper supports the same syntax as the cache helper', function (string $store, array $bootstrappers) {
    config([
        'cache.default' => $store,
        'tenancy.bootstrappers' => $bootstrappers,
    ]);

    if ($store === 'database') withTenantDatabases(true);

    $tenant = Tenant::create();
    $tenant->enter();

    // different stores
    expect(cache()->store()->getStore() !== GlobalCache::store()->getStore())->toBeTrue();
    if ($store === 'redis') {
        // same underlying connection. the prefix is set ON THE STORE
        expect(cache()->store()->getStore()->connection() === global_cache()->store()->getStore()->connection())->toBeTrue();
    } else {
        // different connections
        expect(cache()->store()->getStore()->getConnection()->getName())->toBe('tenant');
        expect(global_cache()->store()->getStore()->getConnection()->getName())->toBe('central');
    }

    expect(cache('foo'))->toBe(null); // tenant cache is empty

    global_cache(['foo' => 'bar']);
    expect(global_cache('foo'))->toBe('bar');

    global_cache()->set('foo', 'baz');
    expect(global_cache()->get('foo'))->toBe('baz');

    expect(cache('foo'))->toBe(null); // tenant cache is not affected
})->with([
    ['redis', [CacheTagsBootstrapper::class]],
    ['redis', [CacheTenancyBootstrapper::class]],
    ['database', [DatabaseTenancyBootstrapper::class, DatabaseCacheBootstrapper::class]],
]);
