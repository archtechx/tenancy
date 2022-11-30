<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Bootstrappers\PrefixCacheTenancyBootstrapper;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;

beforeEach(function () {
    config([
        'tenancy.bootstrappers' => [PrefixCacheTenancyBootstrapper::class],
        'cache.default' => 'redis',
    ]);

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
});

test('cache prefix is separate for each tenant', function () {
    $originalPrefix = config('cache.prefix');
    $prefixBase = config('tenancy.cache.prefix_base');

    expect($originalPrefix . ':') // cache manager postfix ':' to prefix
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

    // Assert tenants' data is accessible using the prefix from the central context
    tenancy()->end();
    config(['cache.prefix' => null]); // stop prefixing cache keys in central so we can provide prefix manually

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
    config([
        'tenancy.cache.prefix_base' => 'custom_'
    ]);

    $tenant1 = Tenant::create();
    tenancy()->initialize($tenant1);

    expect('custom_' . $tenant1->id . ':')
        ->toBe(app('cache')->getPrefix())
        ->toBe(app('cache.store')->getPrefix());
});

