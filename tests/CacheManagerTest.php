<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
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

    /** @test */
    public function default_tag_is_automatically_applied()
    {
        tenancy()->initialize(Tenant::create());

        $this->assertArrayIsSubset([config('tenancy.cache.tag_base') . tenant('id')], cache()->tags('foo')->getTags()->getNames());
    }

    /** @test */
    public function tags_are_merged_when_array_is_passed()
    {
        tenancy()->initialize(Tenant::create());

        $expected = [config('tenancy.cache.tag_base') . tenant('id'), 'foo', 'bar'];
        $this->assertEquals($expected, cache()->tags(['foo', 'bar'])->getTags()->getNames());
    }

    /** @test */
    public function tags_are_merged_when_string_is_passed()
    {
        tenancy()->initialize(Tenant::create());

        $expected = [config('tenancy.cache.tag_base') . tenant('id'), 'foo'];
        $this->assertEquals($expected, cache()->tags('foo')->getTags()->getNames());
    }

    /** @test */
    public function exception_is_thrown_when_zero_arguments_are_passed_to_tags_method()
    {
        tenancy()->initialize(Tenant::create());

        $this->expectException(\Exception::class);
        cache()->tags();
    }

    /** @test */
    public function exception_is_thrown_when_more_than_one_argument_is_passed_to_tags_method()
    {
        tenancy()->initialize(Tenant::create());

        $this->expectException(\Exception::class);
        cache()->tags(1, 2);
    }

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
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
}
