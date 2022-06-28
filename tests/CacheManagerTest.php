<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Tests\Etc\Tenant;

uses(Stancl\Tenancy\Tests\TestCase::class);

beforeEach(function () {
    config(['tenancy.bootstrappers' => [
        CacheTenancyBootstrapper::class,
    ]]);

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
});

test('default tag is automatically applied', function () {
    tenancy()->initialize(Tenant::create());

    $this->assertArrayIsSubset([config('tenancy.cache.tag_base') . tenant('id')], cache()->tags('foo')->getTags()->getNames());
});

test('tags are merged when array is passed', function () {
    tenancy()->initialize(Tenant::create());

    $expected = [config('tenancy.cache.tag_base') . tenant('id'), 'foo', 'bar'];
    $this->assertEquals($expected, cache()->tags(['foo', 'bar'])->getTags()->getNames());
});

test('tags are merged when string is passed', function () {
    tenancy()->initialize(Tenant::create());

    $expected = [config('tenancy.cache.tag_base') . tenant('id'), 'foo'];
    $this->assertEquals($expected, cache()->tags('foo')->getTags()->getNames());
});

test('exception is thrown when zero arguments are passed to tags method', function () {
    tenancy()->initialize(Tenant::create());

    $this->expectException(\Exception::class);
    cache()->tags();
});

test('exception is thrown when more than one argument is passed to tags method', function () {
    tenancy()->initialize(Tenant::create());

    $this->expectException(\Exception::class);
    cache()->tags(1, 2);
});

test('tags separate cache well enough', function () {
    $tenant1 = Tenant::create();
    tenancy()->initialize($tenant1);

    cache()->put('foo', 'bar', 1);
    $this->assertSame('bar', cache()->get('foo'));

    $tenant2 = Tenant::create();
    tenancy()->initialize($tenant2);

    $this->assertNotSame('bar', cache()->get('foo'));

    cache()->put('foo', 'xyz', 1);
    $this->assertSame('xyz', cache()->get('foo'));
});

test('invoking the cache helper works', function () {
    $tenant1 = Tenant::create();
    tenancy()->initialize($tenant1);

    cache(['foo' => 'bar'], 1);
    $this->assertSame('bar', cache('foo'));

    $tenant2 = Tenant::create();
    tenancy()->initialize($tenant2);

    $this->assertNotSame('bar', cache('foo'));

    cache(['foo' => 'xyz'], 1);
    $this->assertSame('xyz', cache('foo'));
});

test('cache is persisted', function () {
    $tenant1 = Tenant::create();
    tenancy()->initialize($tenant1);

    cache(['foo' => 'bar'], 10);
    $this->assertSame('bar', cache('foo'));

    tenancy()->end();

    tenancy()->initialize($tenant1);
    $this->assertSame('bar', cache('foo'));
});

test('cache is persisted when reidentification is used', function () {
    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();
    tenancy()->initialize($tenant1);

    cache(['foo' => 'bar'], 10);
    $this->assertSame('bar', cache('foo'));

    tenancy()->initialize($tenant2);
    tenancy()->end();

    tenancy()->initialize($tenant1);
    $this->assertSame('bar', cache('foo'));
});
