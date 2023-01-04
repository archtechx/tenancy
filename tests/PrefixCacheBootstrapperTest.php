<?php

declare(strict_types=1);

use Illuminate\Cache\RedisStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Tests\Etc\CacheService;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Tests\Etc\CacheManagerService;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\CacheManager as TenancyCacheManager;
use Stancl\Tenancy\Bootstrappers\PrefixCacheTenancyBootstrapper;

beforeEach(function () {
    config([
        'tenancy.bootstrappers' => [
            PrefixCacheTenancyBootstrapper::class
        ],
        'cache.default' => 'redis',
    ]);

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

test('cache prefix is separate for each tenant', function () {
    $originalPrefix = config('cache.prefix');
    $prefixBase = config('tenancy.cache.prefix_base');

    expect($originalPrefix . ':') // RedisStore suffixes prefix with ':'
        ->toBe(app('cache')->getPrefix())
        ->toBe(app('cache.store')->getPrefix());

    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();

    $tenantOnePrefix = $originalPrefix . $prefixBase . $tenant1->getTenantKey();

    tenancy()->initialize($tenant1);
    cache()->set('key', 'tenantone-value');

    expect($tenantOnePrefix . ':')
        ->toBe(app('cache')->getPrefix())
        ->toBe(app('cache.store')->getPrefix());

    $tenantTwoPrefix = $originalPrefix . $prefixBase . $tenant2->getTenantKey();

    tenancy()->initialize($tenant2);

    cache()->set('key', 'tenanttwo-value');

    expect($tenantTwoPrefix . ':')
        ->toBe(app('cache')->getPrefix())
        ->toBe(app('cache.store')->getPrefix());

    // Assert tenants' data is accessible using the prefix from the central context tenancy()->end();

    config(['cache.prefix' => null]); // stop prefixing cache keys in central so we can provide prefix manually
    app('cache')->forgetDriver(config('cache.default'));

    expect(cache($tenantOnePrefix . ':key'))->toBe('tenantone-value');
    expect(cache($tenantTwoPrefix . ':key'))->toBe('tenanttwo-value');
})->group('prefix');

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

test('prefix separate cache well enough', function () {
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

test('prefix separate cache well enough using CacheManager dependency injection', function () {
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

test('stores other than the default one are not prefixed', function () {
    config(['cache.default' => 'redis']);
    config(['cache.stores.redis2' => config('cache.stores.redis')]);

    app(CacheManager::class)->extend('redis2', function($config) {
        $redis = $this->app['redis'];

        $connection = $config['connection'] ?? 'default';

        $store = new RedisStore($redis, $this->getPrefix($config), $connection);

        return $this->repository(
            $store->setLockConnection($config['lock_connection'] ?? $connection)
        );
    });

    $this->app->singleton(CacheManagerService::class);

    app()->make(CacheManagerService::class)->handle();
    expect(cache()->driver('redis2')->get('key'))->toBe('central-value');

    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();
    tenancy()->initialize($tenant1);

    expect(cache()->driver('redis2')->get('key'))->toBe('central-value');
    app()->make(CacheManagerService::class)->handle();
    expect(cache()->driver('redis2')->get('key'))->toBe($tenant1->getTenantKey());

    tenancy()->initialize($tenant2);

    expect(cache()->driver('redis2')->get('key'))->toBe($tenant1->getTenantKey());
    app()->make(CacheManagerService::class)->handle();
    expect(cache()->driver('redis2')->get('key'))->toBe($tenant2->getTenantKey());

    tenancy()->end();
    expect(cache()->driver('redis2')->get('key'))->toBe($tenant2->getTenantKey());
});
