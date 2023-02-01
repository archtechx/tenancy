<?php

declare(strict_types=1);

use Illuminate\Cache\CacheManager;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Tests\Etc\CacheService;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Tests\Etc\SpecificCacheStoreService;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\CacheManager as TenancyCacheManager;
use Stancl\Tenancy\Bootstrappers\PrefixCacheTenancyBootstrapper;

beforeEach(function () {
    config([
        'tenancy.bootstrappers' => [
            PrefixCacheTenancyBootstrapper::class
        ],
        'cache.default' => $cacheDriver = 'redis',
    ]);

    PrefixCacheTenancyBootstrapper::$tenantCacheStores = [$cacheDriver];

    TenancyCacheManager::$addTags = false;

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
});

test('Tenancy overrides CacheManager', function() {
    expect(app('cache')::class)->toBe(TenancyCacheManager::class);
    expect(app(CacheManager::class)::class)->toBe(TenancyCacheManager::class);

    tenancy()->initialize(Tenant::create(['id' => 'first']));

    expect(app('cache')::class)->toBe(TenancyCacheManager::class);
    expect(app(CacheManager::class)::class)->toBe(TenancyCacheManager::class);

    tenancy()->initialize(Tenant::create(['id' => 'second']));

    expect(app('cache')::class)->toBe(TenancyCacheManager::class);
    expect(app(CacheManager::class)::class)->toBe(TenancyCacheManager::class);

    tenancy()->end();

    expect(app('cache')::class)->toBe(TenancyCacheManager::class);
    expect(app(CacheManager::class)::class)->toBe(TenancyCacheManager::class);
});

test('correct cache prefix is used in all contexts', function () {
    $originalPrefix = config('cache.prefix');
    $prefixBase = config('tenancy.cache.prefix_base');
    $expectPrefixToBe = function(string $prefix) {
        expect($prefix . ':') // RedisStore suffixes prefix with ':'
            ->toBe(app('cache')->getPrefix())
            ->toBe(app('cache.store')->getPrefix());
    };

    $expectPrefixToBe($originalPrefix);

    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();

    $tenantOnePrefix = $originalPrefix . $prefixBase . $tenant1->getTenantKey();

    tenancy()->initialize($tenant1);
    cache()->set('key', 'tenantone-value');

    $expectPrefixToBe($tenantOnePrefix);

    $tenantTwoPrefix = $originalPrefix . $prefixBase . $tenant2->getTenantKey();

    tenancy()->initialize($tenant2);

    cache()->set('key', 'tenanttwo-value');

    $expectPrefixToBe($tenantTwoPrefix);

    // Assert tenants' data is accessible using the prefix from the central context tenancy()->end();

    config(['cache.prefix' => null]); // stop prefixing cache keys in central so we can provide prefix manually
    app('cache')->forgetDriver(config('cache.default'));

    expect(cache($tenantOnePrefix . ':key'))->toBe('tenantone-value');
    expect(cache($tenantTwoPrefix . ':key'))->toBe('tenanttwo-value');
});

test('cache is persisted when reidentification is used', function () {
    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();
    tenancy()->initialize($tenant1);

    cache(['foo' => 'bar'], 10);
    expect(cache('foo'))->toBe('bar');

    tenancy()->initialize($tenant2);
    tenancy()->end();

    tenancy()->initialize($tenant1);
    expect(cache('foo'))->toBe('bar');
});

test('prefixing separates the cache', function () {
    $tenant1 = Tenant::create();
    tenancy()->initialize($tenant1);

    cache()->put('foo', 'bar', 1);
    expect(cache()->get('foo'))->toBe('bar');

    $tenant2 = Tenant::create();
    tenancy()->initialize($tenant2);

    pest()->assertNotSame('bar', cache()->get('foo'));

    cache()->put('foo', 'xyz', 1);
    expect(cache()->get('foo'))->toBe('xyz');
});

test('central cache is persisted', function () {
    cache()->put('key', 'central');

    $tenant1 = Tenant::create();
    tenancy()->initialize($tenant1);

    cache()->put('key', 'tenant');

    expect(cache()->get('key'))->toBe('tenant');

    tenancy()->end();
    cache()->put('key2', 'central-two');

    expect(cache()->get('key'))->toBe('central');
    expect(cache()->get('key2'))->toBe('central-two');
});

test('cache base prefix is customizable', function () {
    $originalPrefix = config('cache.prefix');
    $prefixBase = 'custom_';

    config([
        'tenancy.cache.prefix_base' => $prefixBase
    ]);

    $tenant1 = Tenant::create();
    tenancy()->initialize($tenant1);

    expect($originalPrefix . $prefixBase . $tenant1->getTenantKey() . ':')
        ->toBe(app('cache')->getPrefix())
        ->toBe(app('cache.store')->getPrefix());
});

test('cache is prefixed correctly when using a repository injected in a singleton', function () {
    $this->app->singleton(CacheService::class);

    app()->make(CacheService::class)->handle();

    expect(cache('key'))->toBe('central-value');

    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();
    tenancy()->initialize($tenant1);

    expect(cache('key'))->toBeNull();
    app()->make(CacheService::class)->handle();
    expect(cache('key'))->toBe($tenant1->getTenantKey());

    tenancy()->initialize($tenant2);

    expect(cache('key'))->toBeNull();
    app()->make(CacheService::class)->handle();
    expect(cache('key'))->toBe($tenant2->getTenantKey());

    tenancy()->end();

    expect(cache('key'))->toBe('central-value');
});

test('specific central cache store can be used inside a service', function () {
    config(['cache.default' => 'redis']);
    config(['cache.stores.redis2' => config('cache.stores.redis')]);
    $cacheStore = 'redis2'; // Name of the non-default, central cache store that we'll use using cache()->store($cacheStore)

    // Service uses the 'redis2' store which is central/not prefixed (not present in PrefixCacheTenancyBootstrapper::$tenantCacheStores)
    $this->app->singleton(SpecificCacheStoreService::class, function() use ($cacheStore) {
        return new SpecificCacheStoreService($this->app->make(CacheManager::class), $cacheStore);
    });

    app()->make(SpecificCacheStoreService::class)->handle();
    expect(cache()->store($cacheStore)->get('key'))->toBe('central-value');

    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();
    tenancy()->initialize($tenant1);

    // The store isn't prefixed, so the cache isn't separated
    expect(cache()->store($cacheStore)->get('key'))->toBe('central-value');
    app()->make(SpecificCacheStoreService::class)->handle();
    expect(cache()->store($cacheStore)->get('key'))->toBe($tenant1->getTenantKey());

    tenancy()->initialize($tenant2);

    expect(cache()->store($cacheStore)->get('key'))->toBe($tenant1->getTenantKey());
    app()->make(SpecificCacheStoreService::class)->handle();
    expect(cache()->store($cacheStore)->get('key'))->toBe($tenant2->getTenantKey());

    tenancy()->end();
    expect(cache()->store($cacheStore)->get('key'))->toBe($tenant2->getTenantKey());
});

test('stores specified in tenantCacheStores get prefixed', function() {
    // Make the currently used store ('redis') the only store in $tenantCacheStores
    PrefixCacheTenancyBootstrapper::$tenantCacheStores = ['redis'];

    $this->app->singleton(CacheService::class);

    app()->make(CacheService::class)->handle();
    expect(cache('key'))->toBe($centralValue = 'central-value');

    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();

    tenancy()->initialize($tenant1);

    expect(cache('key'))->toBeNull();
    app()->make(CacheService::class)->handle();
    expect(cache('key'))->toBe($tenant1->getTenantKey());

    tenancy()->initialize($tenant2);

    expect(cache('key'))->toBeNull();
    app()->make(CacheService::class)->handle();
    expect(cache('key'))->toBe($tenant2->getTenantKey());

    tenancy()->end();
    expect(cache('key'))->toBe($centralValue);
});

test('stores not specified in tenantCacheStores do not get prefixed', function() {
    config(['cache.stores.redis2' => config('cache.stores.redis')]);
    config(['cache.default' => 'redis2']);
    // Make 'redis' the only store in $tenantCacheStores so that the current store ('redis2') doesn't get prefixed
    PrefixCacheTenancyBootstrapper::$tenantCacheStores = ['redis'];

    $this->app->singleton(CacheService::class);

    app()->make(CacheService::class)->handle();
    expect(cache('key'))->toBe('central-value');

    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();
    tenancy()->initialize($tenant1);

    // The cache isn't prefixed, so it isn't separated
    expect(cache('key'))->toBe('central-value');
    app()->make(CacheService::class)->handle();
    expect(cache('key'))->toBe($tenant1->getTenantKey());

    tenancy()->initialize($tenant2);

    expect(cache('key'))->toBe($tenant1->getTenantKey());
    app()->make(CacheService::class)->handle();
    expect(cache('key'))->toBe($tenant2->getTenantKey());

    tenancy()->end();
    expect(cache('key'))->toBe($tenant2->getTenantKey());
});