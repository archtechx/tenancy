<?php

declare(strict_types=1);

use Illuminate\Cache\CacheManager;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Tests\Etc\CacheService;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Tests\Etc\SpecificCacheStoreService;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;

beforeEach(function () {
    config([
        'tenancy.bootstrappers' => [
            CacheTenancyBootstrapper::class
        ],
        'cache.default' => 'redis',
        'cache.stores.redis2' => config('cache.stores.redis'),
        'tenancy.cache.stores' => ['redis', 'redis2'],
    ]);

    CacheTenancyBootstrapper::$prefixGenerator = null;

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
});

afterEach(function () {
    CacheTenancyBootstrapper::$prefixGenerator = null;
});

test('correct cache prefix is used in all contexts', function () {
    $originalPrefix = config('cache.prefix');
    $prefixFormat = config('tenancy.cache.prefix');
    $getDefaultPrefixForTenant = fn (Tenant $tenant) => $originalPrefix . str($prefixFormat)->replace('%tenant%', $tenant->getTenantKey())->toString();
    $bootstrapper = app(CacheTenancyBootstrapper::class);

    $expectCachePrefixToBe = function (string $prefix) {
        expect($prefix)
            ->toBe(app('cache')->getPrefix())
            ->toBe(app('cache.store')->getPrefix())
            ->toBe(cache()->getPrefix())
            ->toBe(cache()->store('redis2')->getPrefix());
    };

    $expectCachePrefixToBe($originalPrefix);

    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();

    tenancy()->initialize($tenant1);
    cache()->set('key', 'tenantone-value');
    $tenantOnePrefix = $getDefaultPrefixForTenant($tenant1);
    $expectCachePrefixToBe($tenantOnePrefix);
    expect($bootstrapper->generatePrefix($tenant1, 'redis'))->toBe($tenantOnePrefix);

    tenancy()->initialize($tenant2);
    cache()->set('key', 'tenanttwo-value');
    $tenantTwoPrefix = $getDefaultPrefixForTenant($tenant2);
    $expectCachePrefixToBe($tenantTwoPrefix);
    expect($bootstrapper->generatePrefix($tenant2, 'redis'))->toBe($tenantTwoPrefix);

    // Prefix gets reverted to default after ending tenancy
    tenancy()->end();
    $expectCachePrefixToBe($originalPrefix);

    // Assert tenant's data is accessible using the prefix from the central context
    config(['cache.prefix' => null]); // stop prefixing cache keys in central so we can provide prefix manually
    app('cache')->forgetDriver(config('cache.default'));

    expect(cache($tenantOnePrefix . 'key'))->toBe('tenantone-value');
    expect(cache($tenantTwoPrefix . 'key'))->toBe('tenanttwo-value');
});

test('cache is persisted when reidentification is used', function () {
    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();
    tenancy()->initialize($tenant1);

    cache(['foo' => 'bar']);
    expect(cache('foo'))->toBe('bar');

    tenancy()->initialize($tenant2);
    expect(cache('foo'))->toBeNull();
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

    expect(cache()->get('foo'))->toBeNull();

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

    tenancy()->initialize($tenant1);
    expect(cache()->get('key'))->toBe('tenant');
    expect(cache()->get('key2'))->toBeNull();
});

test('only the stores specified in the config get prefixed', function () {
    // Make sure the currently used store ('redis') is the only store in the config
    // This means that the 'redis2' store won't be prefixed
    config(['tenancy.cache.stores' => ['redis']]);

    cache()->store('redis')->put('key', 'central');
    expect(cache()->store('redis')->get('key'))->toBe('central');
    // same values -- the stores use the same connection, with the same prefix here
    expect(cache()->store('redis2')->get('key'))->toBe('central');

    $tenant = Tenant::create();
    tenancy()->initialize($tenant);

    // now the 'redis' store is prefixed, but 'redis2' isn't
    expect(cache()->store('redis2')->get('key'))->toBe('central');
    expect(cache()->store('redis')->get('key'))->toBe(null); // central value not leaked to tenant context

    cache()->store('redis')->put('key', 'tenant'); // change the value of the prefixed store
    expect(cache()->store('redis')->get('key'))->toBe('tenant'); // prefixed store

    tenancy()->end();
    // still central
    expect(cache()->store('redis2')->get('key'))->toBe('central');
    expect(cache()->store('redis')->get('key'))->toBe('central');

    tenancy()->initialize($tenant);
    expect(cache()->store('redis2')->get('key'))->toBe('central'); // still central
    expect(cache()->store('redis')->get('key'))->toBe('tenant');
    cache()->store('redis2')->put('key', 'foo'); // override non-prefixed store value
    cache()->store('redis')->put('key', 'tenant'); // the connection with the prefix still retains the tenant value

    tenancy()->end();
    // both redis2 and redis should now be 'foo' since they got overridden previously
    expect(cache()->store('redis2')->get('key'))->toBe('foo');
    expect(cache()->store('redis')->get('key'))->toBe('foo');
});

test('non default stores get prefixed too when specified in the config', function () {
    config([
        'cache.default' => 'redis',
        'tenancy.cache.stores' => ['redis', 'redis2'],
    ]);

    $tenant = Tenant::create();
    $defaultPrefix = cache()->store()->getPrefix();
    $bootstrapper = app(CacheTenancyBootstrapper::class);

    expect(cache()->store('redis')->getPrefix())->toBe($defaultPrefix);
    expect(cache()->store('redis2')->getPrefix())->toBe($defaultPrefix);

    tenancy()->initialize($tenant);

    expect($bootstrapper->generatePrefix($tenant, 'redis2'))
        ->toBe(cache()->getPrefix())
        ->toBe(cache()->store('redis2')->getPrefix()); // Non-default store

    tenancy()->end();
});

test('cache base prefix is customizable', function () {
    config([
        'tenancy.cache.prefix' => 'custom_%tenant%_'
    ]);

    $originalPrefix = config('cache.prefix');
    $tenant1 = Tenant::create();

    tenancy()->initialize($tenant1);

    expect($originalPrefix . 'custom_' . $tenant1->getTenantKey() . '_')
        ->toBe(cache()->getPrefix())
        ->toBe(cache()->store('redis2')->getPrefix())
        ->toBe(app('cache')->getPrefix())
        ->toBe(app('cache.store')->getPrefix());
});

test('cache store prefix generation can be customized', function() {
    // Use custom prefix generator
    CacheTenancyBootstrapper::generatePrefixUsing($customPrefixGenerator = function (Tenant $tenant) {
        return 'redis_tenant_cache_' . $tenant->getTenantKey();
    });

    expect(CacheTenancyBootstrapper::$prefixGenerator)->toBe($customPrefixGenerator);
    expect(app(CacheTenancyBootstrapper::class)->generatePrefix($tenant = Tenant::create(), 'redis'))
        ->toBe($customPrefixGenerator($tenant));

    tenancy()->initialize($tenant = Tenant::create());

    // Expect the 'redis' store to use the prefix generated by the custom generator
    expect($customPrefixGenerator($tenant))
        ->toBe(cache()->getPrefix())
        ->toBe(cache()->store('redis2')->getPrefix())
        ->toBe(app('cache')->getPrefix())
        ->toBe(app('cache.store')->getPrefix());

    tenancy()->end();
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
    // Make sure 'redis' (the default store) is the only prefixed store
    config(['tenancy.cache.stores' => ['redis']]);
    // Name of the non-default, central cache store that we'll use using cache()->store($cacheStore)
    $cacheStore = 'redis2';

    // Service uses the 'redis2' store which is central/not prefixed (not present in tenancy.cache.stores config)
    // The service's handle() method sets the value of the cache key 'key' to the current tenant key
    // Or to 'central-value' if tenancy isn't initialized
    $this->app->singleton(SpecificCacheStoreService::class, function() use ($cacheStore) {
        return new SpecificCacheStoreService($this->app->make(CacheManager::class), $cacheStore);
    });

    $this->app->make(SpecificCacheStoreService::class)->handle();
    expect(cache()->store($cacheStore)->get('key'))->toBe('central-value');

    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();
    tenancy()->initialize($tenant1);

    // The store isn't prefixed, so the cache isn't separated – the values persist from one context to another
    // Also assert that the value of 'key' is set correctly inside SpecificCacheStoreService according to the current context
    expect(cache()->store($cacheStore)->get('key'))->toBe('central-value');
    $this->app->make(SpecificCacheStoreService::class)->handle();
    expect(cache()->store($cacheStore)->get('key'))->toBe($tenant1->getTenantKey());

    tenancy()->initialize($tenant2);

    expect(cache()->store($cacheStore)->get('key'))->toBe($tenant1->getTenantKey());
    $this->app->make(SpecificCacheStoreService::class)->handle();
    expect(cache()->store($cacheStore)->get('key'))->toBe($tenant2->getTenantKey());

    tenancy()->end();
    // We last executed handle() in tenant2's context, so the value should persist as tenant2's id
    expect(cache()->store($cacheStore)->get('key'))->toBe($tenant2->getTenantKey());
});
