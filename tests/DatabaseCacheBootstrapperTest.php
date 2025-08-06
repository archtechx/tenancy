<?php

declare(strict_types=1);

use Stancl\Tenancy\Bootstrappers\DatabaseCacheBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Event::listen(
        TenantCreated::class,
        JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
            return $event->tenant;
        })->toListener()
    );

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);

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
    // Original connections (store and lock) are 'central' in the config
    expect(config('cache.stores.database.connection'))->toBe('central');
    expect(config('cache.stores.database.lock_connection'))->toBe('central');
    // The actual connection used by the cache store is 'central'
    expect(Cache::store()->getConnection()->getName())->toBe('central');
    // Cache locks also use the 'central' connection
    expect(Cache::lock('foo')->getConnectionName())->toBe('central');

    tenancy()->initialize(Tenant::create());

    // Initializing tenancy should make both connections 'tenant'
    expect(config('cache.stores.database.connection'))->toBe('tenant');
    expect(config('cache.stores.database.lock_connection'))->toBe('tenant');
    // The actual connection used by the cache store and locks is now 'tenant'
    // Purging the database cache store forces the CacheManager to resolve a new instance of
    // the database store, using the connection names specified in the config ('tenant')
    expect(Cache::store()->getConnection()->getName())->toBe('tenant');
    expect(Cache::lock('foo')->getConnectionName())->toBe('tenant');

    tenancy()->end();

    // Ending tenancy should change both connections back to the original ('central')
    expect(config('cache.stores.database.connection'))->toBe('central');
    expect(config('cache.stores.database.lock_connection'))->toBe('central');
    // The actual connection used by the cache store and the cache locks is now 'central' again
    expect(Cache::store()->getConnection()->getName())->toBe('central');
    expect(Cache::lock('foo')->getConnectionName())->toBe('central');
});

test('cache is separated correctly when using DatabaseCacheBootstrapper', function() {
    // DB query for verifying that the cache is stored
    // in the cache table of the current DB connection
    // under the passed key (the key in the DB is prefixed by the default cache prefix).
    $getCacheUsingDbQuery = function (string $cacheKey) {
        // Prefix the cache key with the default cache prefix
        $databaseCacheKey = config('cache.prefix') . $cacheKey;

        return DB::selectOne("SELECT * FROM `cache` WHERE `key` = '{$databaseCacheKey}'")?->value;
    };

    // Create the cache table in the central DB
    Schema::create('cache', function (Blueprint $table) {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->integer('expiration');
    });

    $tenant = Tenant::create();
    $tenant2 = Tenant::create();

    // Create the cache table in tenant DBs
    // With DatabaseCacheBootstrapper, cache will be saved in these tenant DBs instead of the central DB
    tenancy()->runForMultiple([$tenant, $tenant2], function () {
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
    });

    // Write to cache in central context
    cache()->set('foo', 'central');
    expect(Cache::get('foo'))->toBe('central');
    // The value retrieved by the DB query is formatted like "s:7:"central";".
    // We use toContain() because of this formatting instead of just toBe().
    expect($getCacheUsingDbQuery('foo'))->toContain('central');

    tenancy()->initialize($tenant);

    // Central cache doesn't leak to tenant context
    expect(Cache::has('foo'))->toBeFalse();
    // Verify that the 'foo' cache key doesn't exist in the tenant's DB using a direct DB query
    expect($getCacheUsingDbQuery('foo'))->toBeNull();

    cache()->set('foo', 'bar');
    expect(Cache::get('foo'))->toBe('bar');
    // Verify that the 'foo' cache key is 'bar' using a direct DB query
    expect($getCacheUsingDbQuery('foo'))->toContain('bar');

    tenancy()->initialize($tenant2);

    // Assert one tenant's cache doesn't leak to another tenant
    expect(Cache::has('foo'))->toBeFalse();
    // The 'foo' cache key in another tenant's database shouldn't exist
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
    // Configure multiple stores with different drivers
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
