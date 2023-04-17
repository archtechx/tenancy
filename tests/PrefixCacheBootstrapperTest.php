<?php

declare(strict_types=1);

use Illuminate\Cache\CacheManager;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Tests\Etc\CacheService;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\CacheManager as TenancyCacheManager;
use Stancl\Tenancy\Tests\Etc\SpecificCacheStoreService;
use Stancl\Tenancy\Bootstrappers\PrefixCacheTenancyBootstrapper;

beforeEach(function () {
    config([
        'tenancy.bootstrappers' => [
            PrefixCacheTenancyBootstrapper::class
        ],
        'cache.default' => $cacheDriver = 'redis',
    ]);

    PrefixCacheTenancyBootstrapper::$tenantCacheStores = [$cacheDriver];
    PrefixCacheTenancyBootstrapper::$prefixGenerator = null;

    TenancyCacheManager::$addTags = false;

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
});

afterEach(function () {
    PrefixCacheTenancyBootstrapper::$tenantCacheStores = [];
    PrefixCacheTenancyBootstrapper::$prefixGenerator = null;
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
    $getDefaultPrefixForTenant = fn (Tenant $tenant) => $originalPrefix . $prefixBase . $tenant->getTenantKey();
    $generatePrefixForTenant = function (Tenant $tenant) {
        return app(PrefixCacheTenancyBootstrapper::class)->generatePrefix($tenant);
    };

    $expectCachePrefixToBe = function (string $prefix) {
        expect($prefix . ':') // RedisStore suffixes prefix with ':'
            ->toBe(app('cache')->getPrefix())
            ->toBe(app('cache.store')->getPrefix());
    };

    $expectCachePrefixToBe($originalPrefix);

    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();

    tenancy()->initialize($tenant1);
    cache()->set('key', 'tenantone-value');
    expect($generatePrefixForTenant($tenant1))->toBe($tenantOnePrefix = $getDefaultPrefixForTenant($tenant1));
    $expectCachePrefixToBe($tenantOnePrefix);

    tenancy()->initialize($tenant2);
    cache()->set('key', 'tenanttwo-value');
    expect($generatePrefixForTenant($tenant2))->toBe($tenantTwoPrefix = $getDefaultPrefixForTenant($tenant2));
    $expectCachePrefixToBe($tenantTwoPrefix);

    // Prefix gets reverted to default after ending tenancy
    tenancy()->end();
    $expectCachePrefixToBe($originalPrefix);

    // Assert tenant's data is accessible using the prefix from the central context
    config(['cache.prefix' => null]); // stop prefixing cache keys in central so we can provide prefix manually
    app('cache')->forgetDriver(config('cache.default'));

    expect(cache($tenantOnePrefix . ':key'))->toBe('tenantone-value');
    expect(cache($tenantTwoPrefix . ':key'))->toBe('tenanttwo-value');
});

test('cache is persisted when reidentification is used', function () {
    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();
    tenancy()->initialize($tenant1);

    cache(['foo' => 'bar']);
    expect(cache('foo'))->toBe('bar');

    tenancy()->initialize($tenant2);
    expect(cache('foo'))->not()->toBe('bar');
    tenancy()->end();

    tenancy()->initialize($tenant1);
    expect(cache('foo'))->toBe('bar');
});

test('prefixing separates the cache', function () {
    $tenant1 = Tenant::create();
    tenancy()->initialize($tenant1);

    cache()->put('foo', 'bar');
    expect(cache()->get('foo'))->toBe('bar');

    $tenant2 = Tenant::create();
    tenancy()->initialize($tenant2);

    expect(cache()->get('foo'))->not()->toBe('bar');

    cache()->put('foo', 'xyz');
    expect(cache()->get('foo'))->toBe('xyz');

    tenancy()->initialize($tenant1);
    expect(cache()->get('foo'))->toBe('bar');
});

test('central cache is persisted', function () {
    cache()->put('key', 'central');

    $tenant1 = Tenant::create();
    tenancy()->initialize($tenant1);

    expect(cache('key'))->toBeNull();
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

    expect(cache('key'))->toBeNull();

    $this->app->make(CacheService::class)->handle();

    expect(cache('key'))->toBe('central-value');

    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();
    tenancy()->initialize($tenant1);

    expect(cache('key'))->toBeNull();
    $this->app->make(CacheService::class)->handle();
    expect(cache('key'))->toBe($tenant1->getTenantKey());

    tenancy()->initialize($tenant2);

    expect(cache('key'))->toBeNull();
    $this->app->make(CacheService::class)->handle();
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

    $this->app->make(SpecificCacheStoreService::class)->handle();
    expect(cache()->store($cacheStore)->get('key'))->toBe('central-value');

    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();
    tenancy()->initialize($tenant1);

    // The store isn't prefixed, so the cache isn't separated
    expect(cache()->store($cacheStore)->get('key'))->toBe('central-value');
    $this->app->make(SpecificCacheStoreService::class)->handle();
    expect(cache()->store($cacheStore)->get('key'))->toBe($tenant1->getTenantKey());

    tenancy()->initialize($tenant2);

    expect(cache()->store($cacheStore)->get('key'))->toBe($tenant1->getTenantKey());
    $this->app->make(SpecificCacheStoreService::class)->handle();
    expect(cache()->store($cacheStore)->get('key'))->toBe($tenant2->getTenantKey());

    tenancy()->end();
    expect(cache()->store($cacheStore)->get('key'))->toBe($tenant2->getTenantKey());
});

test('only the stores specified in tenantCacheStores get prefixed', function() {
    config(['cache.stores.redis2' => config('cache.stores.redis')]);

    // Make the currently used store ('redis') the only store in $tenantCacheStores
    PrefixCacheTenancyBootstrapper::$tenantCacheStores = ['redis'];

    $this->app->singleton(CacheService::class);

    $this->app->make(CacheService::class)->handle();
    expect(cache('key'))->toBe($centralValue = 'central-value');

    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();

    tenancy()->initialize($tenant1);

    expect(cache('key'))->toBeNull();
    $this->app->make(CacheService::class)->handle();
    expect(cache('key'))->toBe($tenant1->getTenantKey());

    // Switch to an unprefixed store
    config(['cache.default' => 'redis2']);
    expect(cache('key'))->toBe($centralValue);
    config(['cache.default' => 'redis']);

    tenancy()->initialize($tenant2);

    expect(cache('key'))->toBeNull();
    $this->app->make(CacheService::class)->handle();
    expect(cache('key'))->toBe($tenant2->getTenantKey());

    // Switch to an unprefixed store
    config(['cache.default' => 'redis2']);
    expect(cache('key'))->toBe($centralValue);
    // Switch back to the prefixed store
    config(['cache.default' => 'redis']);

    tenancy()->end();
    expect(cache('key'))->toBe($centralValue);

    $this->app->make(CacheService::class)->handle();
    expect(cache('key'))->toBe($centralValue);

    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();

    tenancy()->initialize($tenant1);

    expect(cache('key'))->toBeNull();
    $this->app->make(CacheService::class)->handle();
    expect(cache('key'))->toBe($tenant1->getTenantKey());

    // Switch to an unprefixed store
    config(['cache.default' => 'redis2']);
    expect(cache('key'))->toBe($centralValue);
    // Switch back to the prefixed store
    config(['cache.default' => 'redis']);

    tenancy()->initialize($tenant2);

    expect(cache('key'))->toBeNull();
    $this->app->make(CacheService::class)->handle();
    expect(cache('key'))->toBe($tenant2->getTenantKey());

    // Switch to an unprefixed store
    config(['cache.default' => 'redis2']);
    expect(cache('key'))->toBe($centralValue);
    // Switch back to the prefixed store
    config(['cache.default' => 'redis']);

    tenancy()->end();
    expect(cache('key'))->toBe($centralValue);
});

test('non default stores get prefixed too', function () {
    $generatePrefixForTenant = function (Tenant $tenant) {
        return app(PrefixCacheTenancyBootstrapper::class)->generatePrefix($tenant);
    };

    config(['cache.stores.redis2' => config('cache.stores.redis')]);
    // Make 'redis2' the default cache driver
    config(['cache.default' => 'redis2']);
    PrefixCacheTenancyBootstrapper::$tenantCacheStores = ['redis', 'redis2'];

    // The prefix is the same for both drivers in the central context
    $tenant = Tenant::create();
    $defaultPrefix = cache()->store()->getPrefix();

    expect(cache()->store('redis')->getPrefix())->toBe($defaultPrefix);

    tenancy()->initialize($tenant);

    $expectedPrefix = $generatePrefixForTenant($tenant);

    // We didn't add a prefix generator for our 'redis' driver, so we expect the prefix to be generated using the 'default' generator
    expect(cache()->store()->getPrefix())->toBe($expectedPrefix . ':');
    // Non-default store
    expect(cache()->store('redis')->getPrefix())->toBe($expectedPrefix . ':');

    tenancy()->end();
});

test('cache store prefix generation can be customized', function() {
    config(['cache.default' => 'redis']);
    PrefixCacheTenancyBootstrapper::$tenantCacheStores = ['redis'];

    // Add custom prefix generator for the 'redis' store
    PrefixCacheTenancyBootstrapper::generatePrefixUsing($customPrefixGenerator = function (Tenant $tenant) {
        return 'redis_tenant_cache_' . $tenant->getTenantKey();
    });

    expect(PrefixCacheTenancyBootstrapper::$prefixGenerator)->toBe($customPrefixGenerator);

    tenancy()->initialize($tenant = Tenant::create());

    // Expect the 'redis' store to use the prefix generated by the custom generator
    expect(cache()->store('redis')->getPrefix())->toBe($customPrefixGenerator($tenant) . ':');

    tenancy()->end();
});

test('stores get prefixed using the default way if the store does not have a corresponding generator', function() {
    config(['cache.stores.redis2' => config('cache.stores.redis')]);
    // Make 'redis2' the default cache driver
    config(['cache.default' => 'redis2']);
    $originalPrefix = config('cache.prefix');
    $prefixBase = config('tenancy.cache.prefix_base');

    $tenant = Tenant::create();
    $defaultPrefix = $originalPrefix . $prefixBase . $tenant->getTenantKey();

    PrefixCacheTenancyBootstrapper::$tenantCacheStores = ['redis', 'redis2'];

    // Don't add a generator for 'redis2'
    // Let the prefix get created using the default approach
    tenancy()->initialize($tenant);

    expect(cache()->store()->getPrefix())->toBe($defaultPrefix . ':');
    // Other stores without a prefix generator use the default generator too
    expect(cache()->store('redis')->getPrefix())->toBe($defaultPrefix . ':');

    tenancy()->end();
});
