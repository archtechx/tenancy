<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Cache\CacheManager as BaseCacheManager;
use Illuminate\Cache\MemoizedStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Tests\Etc\Tenant;

class CacheManagerTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        config(['tenancy.bootstrappers' => [
            CacheTenancyBootstrapper::class,
        ]]);

        Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    }

    #[Test]
    public function default_tag_is_automatically_applied()
    {
        tenancy()->initialize(Tenant::create());

        $this->assertArrayIsSubset([config('tenancy.cache.tag_base') . tenant('id')], cache()->tags('foo')->getTags()->getNames());
    }

    #[Test]
    public function tags_are_merged_when_array_is_passed()
    {
        tenancy()->initialize(Tenant::create());

        $expected = [config('tenancy.cache.tag_base') . tenant('id'), 'foo', 'bar'];
        $this->assertEquals($expected, cache()->tags(['foo', 'bar'])->getTags()->getNames());
    }

    #[Test]
    public function tags_are_merged_when_string_is_passed()
    {
        tenancy()->initialize(Tenant::create());

        $expected = [config('tenancy.cache.tag_base') . tenant('id'), 'foo'];
        $this->assertEquals($expected, cache()->tags('foo')->getTags()->getNames());
    }

    #[Test]
    public function exception_is_thrown_when_zero_arguments_are_passed_to_tags_method()
    {
        tenancy()->initialize(Tenant::create());

        $this->expectException(\Exception::class);
        cache()->tags();
    }

    #[Test]
    public function exception_is_thrown_when_more_than_one_argument_is_passed_to_tags_method()
    {
        tenancy()->initialize(Tenant::create());

        $this->expectException(\Exception::class);
        cache()->tags(1, 2);
    }

    #[Test]
    public function tags_separate_cache_well_enough()
    {
        $tenant1 = Tenant::create();
        tenancy()->initialize($tenant1);

        cache()->put('foo', 'bar', 1);
        $this->assertSame('bar', cache()->get('foo'));

        $tenant2 = Tenant::create();
        tenancy()->initialize($tenant2);

        $this->assertNotSame('bar', cache()->get('foo'));

        cache()->put('foo', 'xyz', 1);
        $this->assertSame('xyz', cache()->get('foo'));
    }

    #[Test]
    public function invoking_the_cache_helper_works()
    {
        $tenant1 = Tenant::create();
        tenancy()->initialize($tenant1);

        cache(['foo' => 'bar'], 1);
        $this->assertSame('bar', cache('foo'));

        $tenant2 = Tenant::create();
        tenancy()->initialize($tenant2);

        $this->assertNotSame('bar', cache('foo'));

        cache(['foo' => 'xyz'], 1);
        $this->assertSame('xyz', cache('foo'));
    }

    #[Test]
    public function cache_is_persisted()
    {
        $tenant1 = Tenant::create();
        tenancy()->initialize($tenant1);

        cache(['foo' => 'bar'], 10);
        $this->assertSame('bar', cache('foo'));

        tenancy()->end();

        tenancy()->initialize($tenant1);
        $this->assertSame('bar', cache('foo'));
    }

    #[Test]
    public function cache_is_persisted_when_reidentification_is_used()
    {
        $tenant1 = Tenant::create();
        $tenant2 = Tenant::create();
        tenancy()->initialize($tenant1);

        cache(['foo' => 'bar'], 10);
        $this->assertSame('bar', cache('foo'));

        tenancy()->initialize($tenant2);
        tenancy()->end();

        tenancy()->initialize($tenant1);
        $this->assertSame('bar', cache('foo'));
    }

    #[Test]
    public function memoized_cache_is_separated_between_tenants()
    {
        $this->skipIfMemoIsNotSupported();

        $tenant1 = Tenant::create();
        $tenant2 = Tenant::create();

        tenancy()->initialize($tenant1);
        cache()->memo()->put('foo', 'bar', 10);
        $this->assertSame('bar', cache()->memo()->get('foo'));

        tenancy()->initialize($tenant2);
        $this->assertNull(cache()->memo()->get('foo'));

        cache()->memo()->put('foo', 'xyz', 10);
        $this->assertSame('xyz', cache()->memo()->get('foo'));

        tenancy()->initialize($tenant1);
        $this->assertSame('bar', cache()->memo()->get('foo'));
    }

    #[Test]
    public function memoized_cache_does_not_leak_from_the_central_context()
    {
        $this->skipIfMemoIsNotSupported();

        Event::listen(TenancyEnded::class, RevertToCentralContext::class);

        cache()->memo()->put('foo', 'central', 10);
        $this->assertSame('central', cache()->memo()->get('foo'));

        tenancy()->initialize(Tenant::create());
        $this->assertNull(cache()->memo()->get('foo'));

        tenancy()->end();
        $this->assertSame('central', cache()->memo()->get('foo'));
    }

    #[Test]
    public function memoized_cache_uses_tenant_tags()
    {
        $this->skipIfMemoIsNotSupported();

        tenancy()->initialize(Tenant::create());

        Cache::memo()->put('foo', 'bar', 10);

        $this->assertSame('bar', cache()->get('foo'));
        $this->assertSame('bar', cache()->tags([])->get('foo'));
        $this->assertSame('bar', Cache::memo()->get('foo'));
    }

    #[Test]
    public function flushing_memoized_cache_only_flushes_the_current_tenant()
    {
        $this->skipIfMemoIsNotSupported();

        $tenant1 = Tenant::create();
        $tenant2 = Tenant::create();

        tenancy()->initialize($tenant1);
        cache()->memo()->put('foo', 'bar', 10);

        tenancy()->initialize($tenant2);
        cache()->memo()->put('foo', 'xyz', 10);
        cache()->memo()->flush();
        $this->assertNull(cache()->memo()->get('foo'));

        tenancy()->initialize($tenant1);
        $this->assertSame('bar', cache()->memo()->get('foo'));
    }

    #[Test]
    public function memoized_tagged_cache_is_separated_between_tenants()
    {
        $this->skipIfMemoIsNotSupported();

        if (! method_exists(MemoizedStore::class, 'tags')) {
            $this->markTestSkipped('Memoized cache does not support tags before Laravel 13.');
        }

        $tenant1 = Tenant::create();
        $tenant2 = Tenant::create();

        tenancy()->initialize($tenant1);
        cache()->memo()->tags(['foo'])->put('bar', 'baz', 10);
        $this->assertSame('baz', cache()->memo()->tags(['foo'])->get('bar'));
        $this->assertSame('baz', cache()->tags(['foo'])->get('bar'));

        tenancy()->initialize($tenant2);
        $this->assertNull(cache()->memo()->tags(['foo'])->get('bar'));

        cache()->memo()->tags(['foo'])->put('bar', 'xyz', 10);
        $this->assertSame('xyz', cache()->memo()->tags(['foo'])->get('bar'));

        tenancy()->initialize($tenant1);
        $this->assertSame('baz', cache()->memo()->tags(['foo'])->get('bar'));
    }

    #[Test]
    public function memoized_cache_can_be_spied_on_in_tenant_context()
    {
        $this->skipIfMemoIsNotSupported();

        tenancy()->initialize(Tenant::create());

        cache()->put('foo', 'bar', 10);

        Cache::spy();

        $this->assertSame('bar', Cache::memo()->get('foo'));

        Cache::memo()->shouldHaveReceived('get')->with('foo')->once();
    }

    #[Test]
    public function memoized_repositories_do_not_add_container_bindings_per_tenant_and_are_reset_with_scoped_instances()
    {
        $this->skipIfMemoIsNotSupported();

        $memoBindings = fn () => collect(array_keys(app()->getBindings()))
            ->filter(fn (string $key) => str_starts_with($key, 'cache.__memoized'))
            ->count();

        $before = $memoBindings();

        foreach (range(1, 3) as $_) {
            tenancy()->initialize(Tenant::create());
            cache()->memo()->get('foo');
        }

        $this->assertSame($before + 1, $memoBindings());

        $repository = cache()->memo();
        $this->assertSame($repository, cache()->memo());

        app()->forgetScopedInstances();

        $this->assertNotSame($repository, cache()->memo());
    }

    protected function skipIfMemoIsNotSupported(): void
    {
        if (! method_exists(BaseCacheManager::class, 'memo')) {
            $this->markTestSkipped('Cache::memo() was added in Laravel 12.9.');
        }
    }
}
