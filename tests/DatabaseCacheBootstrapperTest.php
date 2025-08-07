<?php

declare(strict_types=1);

use Stancl\Tenancy\Bootstrappers\DatabaseCacheBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

use function Stancl\Tenancy\Tests\withBootstrapping;
use function Stancl\Tenancy\Tests\withCacheTables;
use function Stancl\Tenancy\Tests\withTenantDatabases;

beforeEach(function () {
    withBootstrapping();
    withCacheTables();
    withTenantDatabases(true);

    DatabaseCacheBootstrapper::$stores = null;

    config([
        'cache.stores.database.connection' => 'central', // Explicitly set cache DB connection name in config
        'cache.stores.database.lock_connection' => 'central', // Also set lock connection name
        'cache.default' => 'database',
        'tenancy.bootstrappers' => [
            DatabaseTenancyBootstrapper::class,
            DatabaseCacheBootstrapper::class, // Used instead of CacheTenancyBootstrapper
        ],
    ]);
});

afterEach(function () {
    DatabaseCacheBootstrapper::$stores = null;
});

test('DatabaseCacheBootstrapper switches the database cache store connections correctly', function () {
    expect(config('cache.stores.database.connection'))->toBe('central');
    expect(config('cache.stores.database.lock_connection'))->toBe('central');
    expect(Cache::store()->getConnection()->getName())->toBe('central');
    expect(Cache::lock('foo')->getConnectionName())->toBe('central');

    tenancy()->initialize(Tenant::create());

    expect(config('cache.stores.database.connection'))->toBe('tenant');
    expect(config('cache.stores.database.lock_connection'))->toBe('tenant');
    expect(Cache::store()->getConnection()->getName())->toBe('tenant');
    expect(Cache::lock('foo')->getConnectionName())->toBe('tenant');

    tenancy()->end();

    expect(config('cache.stores.database.connection'))->toBe('central');
    expect(config('cache.stores.database.lock_connection'))->toBe('central');
    expect(Cache::store()->getConnection()->getName())->toBe('central');
    expect(Cache::lock('foo')->getConnectionName())->toBe('central');
});

test('cache is separated correctly when using DatabaseCacheBootstrapper', function() {
    // We need the prefix later for lower-level assertions. Let's store it
    // once now and reuse this variable rather than re-fetching it to make
    // it clear that the scoping does NOT come from a prefix change.

    $cachePrefix = config('cache.prefix');
    $getCacheUsingDbQuery = fn (string $cacheKey) =>
        DB::selectOne("SELECT * FROM `cache` WHERE `key` = '{$cachePrefix}{$cacheKey}'")?->value;

    $tenant = Tenant::create();
    $tenant2 = Tenant::create();

    // Write to cache in central context
    cache()->set('foo', 'central');
    expect(Cache::get('foo'))->toBe('central');
    // The value retrieved by the DB query is formatted like "s:7:"central";".
    // We use toContain() because of this formatting instead of just toBe().
    expect($getCacheUsingDbQuery('foo'))->toContain('central');

    tenancy()->initialize($tenant);

    // Central cache doesn't leak to tenant context
    expect(Cache::has('foo'))->toBeFalse();
    expect($getCacheUsingDbQuery('foo'))->toBeNull();

    cache()->set('foo', 'bar');
    expect(Cache::get('foo'))->toBe('bar');
    expect($getCacheUsingDbQuery('foo'))->toContain('bar');

    tenancy()->initialize($tenant2);

    // Assert one tenant's cache doesn't leak to another tenant
    expect(Cache::has('foo'))->toBeFalse();
    expect($getCacheUsingDbQuery('foo'))->toBeNull();

    cache()->set('foo', 'xyz');
    expect(Cache::get('foo'))->toBe('xyz');
    expect($getCacheUsingDbQuery('foo'))->toContain('xyz');

    tenancy()->initialize($tenant);

    // Assert cache didn't leak to the original tenant
    expect(Cache::get('foo'))->toBe('bar');
    expect($getCacheUsingDbQuery('foo'))->toContain('bar');

    tenancy()->end();

    // Assert central 'foo' cache is still the same ('central')
    expect(Cache::get('foo'))->toBe('central');
    expect($getCacheUsingDbQuery('foo'))->toContain('central');
});

test('DatabaseCacheBootstrapper auto-detects all database driver stores by default', function() {
    config([
        'cache.stores.database' => [
            'driver' => 'database',
            'connection' => 'central',
            'table' => 'cache',
        ],
        'cache.stores.sessions' => [
            'driver' => 'database',
            'connection' => 'central',
            'table' => 'sessions_cache',
        ],
        'cache.stores.redis' => [
            'driver' => 'redis',
            'connection' => 'default',
        ],
        'cache.stores.file' => [
            'driver' => 'file',
            'path' => '/foo/bar',
        ],
    ]);

    // Here, we're using auto-detection (default behavior)
    expect(config('cache.stores.database.connection'))->toBe('central');
    expect(config('cache.stores.sessions.connection'))->toBe('central');
    expect(config('cache.stores.redis.connection'))->toBe('default');
    expect(config('cache.stores.file.path'))->toBe('/foo/bar');

    tenancy()->initialize(Tenant::create());

    // Using auto-detection (default behavior),
    // all database driver stores should be configured,
    // and stores with non-database drivers are ignored.
    expect(config('cache.stores.database.connection'))->toBe('tenant');
    expect(config('cache.stores.sessions.connection'))->toBe('tenant');
    expect(config('cache.stores.redis.connection'))->toBe('default'); // unchanged
    expect(config('cache.stores.file.path'))->toBe('/foo/bar'); // unchanged

    tenancy()->end();

    // All database stores should be reverted, others unchanged
    expect(config('cache.stores.database.connection'))->toBe('central');
    expect(config('cache.stores.sessions.connection'))->toBe('central');
    expect(config('cache.stores.redis.connection'))->toBe('default');
    expect(config('cache.stores.file.path'))->toBe('/foo/bar');
});

test('manual $stores configuration takes precedence over auto-detection', function() {
    // Configure multiple database stores
    config([
        'cache.stores.sessions' => [
            'driver' => 'database',
            'connection' => 'central',
            'table' => 'sessions_cache',
        ],
        'cache.stores.redis' => [
            'driver' => 'redis',
            'connection' => 'default',
        ],
    ]);

    // Specific store overrides (including non-database stores)
    DatabaseCacheBootstrapper::$stores = ['sessions', 'redis']; // Note: excludes 'database'

    expect(config('cache.stores.database.connection'))->toBe('central');
    expect(config('cache.stores.sessions.connection'))->toBe('central');
    expect(config('cache.stores.redis.connection'))->toBe('default');

    tenancy()->initialize(Tenant::create());

    // Manual config takes precedence: only 'sessions' is configured
    // - redis filtered out by driver check
    // - database store not included in $stores
    expect(config('cache.stores.database.connection'))->toBe('central'); // Excluded in manual config
    expect(config('cache.stores.sessions.connection'))->toBe('tenant'); // Included and is database driver
    expect(config('cache.stores.redis.connection'))->toBe('default'); // Included but filtered out (not database driver)

    tenancy()->end();

    // Only the manually configured stores' config will be reverted
    expect(config('cache.stores.database.connection'))->toBe('central');
    expect(config('cache.stores.sessions.connection'))->toBe('central');
    expect(config('cache.stores.redis.connection'))->toBe('default');
});
