<?php

declare(strict_types=1);

use Stancl\Tenancy\Tests\Etc\Tenant;
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

    cache()->put('foo', 'bar', 1);
    expect(cache()->get('foo'))->toBe('bar');

    $tenant2 = Tenant::create();
    tenancy()->initialize($tenant2);

    expect(cache('foo'))->not()->toBe('bar');

    cache()->put('foo', 'xyz', 1);
    expect(cache()->get('foo'))->toBe('xyz');
});

test('invoking the cache helper works', function () {
    $tenant1 = Tenant::create();
    tenancy()->initialize($tenant1);

    cache(['foo' => 'bar'], 1);
    expect(cache('foo'))->toBe('bar');

    $tenant2 = Tenant::create();
    tenancy()->initialize($tenant2);

    expect(cache('foo'))->not()->toBe('bar');

    cache(['foo' => 'xyz'], 1);
    expect(cache('foo'))->toBe('xyz');
});

test('cache is persisted', function () {
    $tenant1 = Tenant::create();
    tenancy()->initialize($tenant1);

    cache(['foo' => 'bar'], 10);
    expect(cache('foo'))->toBe('bar');

    tenancy()->end();

    tenancy()->initialize($tenant1);
    expect(cache('foo'))->toBe('bar');
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
