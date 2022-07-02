<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Facades\GlobalCache;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Tests\Etc\Tenant;

beforeEach(function () {
    config(['tenancy.bootstrappers' => [
        CacheTenancyBootstrapper::class,
    ]]);

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
});

test('global cache manager stores data in global cache', function () {
    expect(cache('foo'))->toBe(null);
    GlobalCache::put(['foo' => 'bar'], 1);
    expect(GlobalCache::get('foo'))->toBe('bar');

    $tenant1 = Tenant::create();
    tenancy()->initialize($tenant1);
    expect(GlobalCache::get('foo'))->toBe('bar');

    GlobalCache::put(['abc' => 'xyz'], 1);
    cache(['def' => 'ghi'], 10);
    expect(cache('def'))->toBe('ghi');

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
});

