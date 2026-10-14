<?php

declare(strict_types=1);

use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Bootstrappers\CacheTagsBootstrapper;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use function Stancl\Tenancy\Tests\pest;

beforeEach(function () {
    config(['tenancy.bootstrappers' => [CacheTagsBootstrapper::class]]);

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
});

test('default tag is automatically applied', function () {
    tenancy()->initialize(Tenant::create());

    pest()->assertArrayIsSubset([config('tenancy.cache.tag_base') . tenant('id')], cache()->tags('foo')->getTags()->getNames());
});

test('tags are merged when array is passed', function () {
    tenancy()->initialize(Tenant::create());

    $expected = [config('tenancy.cache.tag_base') . tenant('id'), 'foo', 'bar'];
    expect(cache()->tags(['foo', 'bar'])->getTags()->getNames())->toEqual($expected);
});

test('tags are merged when string is passed', function () {
    tenancy()->initialize(Tenant::create());

    $expected = [config('tenancy.cache.tag_base') . tenant('id'), 'foo'];
    expect(cache()->tags('foo')->getTags()->getNames())->toEqual($expected);
});

test('exception is thrown when zero arguments are passed to tags method', function () {
    tenancy()->initialize(Tenant::create());

    pest()->expectException(\Exception::class);
    cache()->tags();
});

test('exception is thrown when more than one argument is passed to tags method', function () {
    tenancy()->initialize(Tenant::create());

    pest()->expectException(\Exception::class);
    cache()->tags(1, 2);
});

test('tags separate cache properly', function () {
    $tenant1 = Tenant::create();
    tenancy()->initialize($tenant1);

    cache()->put('foo', 'bar');
    expect(cache()->get('foo'))->toBe('bar');

    $tenant2 = Tenant::create();
    tenancy()->initialize($tenant2);

    expect(cache('foo'))->not()->toBe('bar');

    cache()->put('foo', 'xyz');
    expect(cache()->get('foo'))->toBe('xyz');
});

test('invoking the cache helper works', function () {
    $tenant1 = Tenant::create();
    tenancy()->initialize($tenant1);

    cache(['foo' => 'bar']);
    expect(cache('foo'))->toBe('bar');

    $tenant2 = Tenant::create();
    tenancy()->initialize($tenant2);

    expect(cache('foo'))->not()->toBe('bar');

    cache(['foo' => 'xyz']);
    expect(cache('foo'))->toBe('xyz');
});

test('cache is persisted', function () {
    $tenant1 = Tenant::create();
    tenancy()->initialize($tenant1);

    cache(['foo' => 'bar']);
    expect(cache('foo'))->toBe('bar');

    tenancy()->end();

    tenancy()->initialize($tenant1);
    expect(cache('foo'))->toBe('bar');
});

test('cache is persisted when reidentification is used', function () {
    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();
    tenancy()->initialize($tenant1);

    cache(['foo' => 'bar']);
    expect(cache('foo'))->toBe('bar');

    tenancy()->initialize($tenant2);
    tenancy()->end();

    tenancy()->initialize($tenant1);
    expect(cache('foo'))->toBe('bar');
});

test('memoized cache is separated between tenants', function () {
    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();

    tenancy()->initialize($tenant1);
    cache()->memo()->put('foo', 'bar');
    expect(cache()->memo()->get('foo'))->toBe('bar');

    tenancy()->initialize($tenant2);
    expect(cache()->memo()->get('foo'))->toBeNull();

    cache()->memo()->put('foo', 'xyz');
    expect(cache()->memo()->get('foo'))->toBe('xyz');

    tenancy()->initialize($tenant1);
    expect(cache()->memo()->get('foo'))->toBe('bar');
});

test('memoized cache does not leak from the central context', function () {
    cache()->memo()->put('foo', 'central');
    expect(cache()->memo()->get('foo'))->toBe('central');

    tenancy()->initialize(Tenant::create());
    expect(cache()->memo()->get('foo'))->toBeNull();

    tenancy()->end();
    expect(cache()->memo()->get('foo'))->toBe('central');
});

test('memoized cache uses tenant tags', function () {
    tenancy()->initialize(Tenant::create());

    Cache::memo()->put('foo', 'bar');

    expect(cache()->get('foo'))->toBe('bar');
    expect(cache()->tags([])->get('foo'))->toBe('bar');
    expect(Cache::memo()->get('foo'))->toBe('bar');
});

test('flushing memoized cache only flushes the current tenant', function () {
    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();

    tenancy()->initialize($tenant1);
    cache()->memo()->put('foo', 'bar');

    tenancy()->initialize($tenant2);
    cache()->memo()->put('foo', 'xyz');
    cache()->memo()->flush();
    expect(cache()->memo()->get('foo'))->toBeNull();

    tenancy()->initialize($tenant1);
    expect(cache()->memo()->get('foo'))->toBe('bar');
});

test('memoized tagged cache is separated between tenants', function () {
    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();

    tenancy()->initialize($tenant1);
    cache()->memo()->tags(['foo'])->put('bar', 'baz');
    expect(cache()->memo()->tags(['foo'])->get('bar'))->toBe('baz');
    expect(cache()->tags(['foo'])->get('bar'))->toBe('baz');

    tenancy()->initialize($tenant2);
    expect(cache()->memo()->tags(['foo'])->get('bar'))->toBeNull();

    cache()->memo()->tags(['foo'])->put('bar', 'xyz');
    expect(cache()->memo()->tags(['foo'])->get('bar'))->toBe('xyz');

    tenancy()->initialize($tenant1);
    expect(cache()->memo()->tags(['foo'])->get('bar'))->toBe('baz');
});

test('memoized cache can be spied on in tenant context', function () {
    tenancy()->initialize(Tenant::create());

    cache()->put('foo', 'bar');

    Cache::spy();

    expect(Cache::memo()->get('foo'))->toBe('bar');

    Cache::memo()->shouldHaveReceived('get')->with('foo')->once();
});
