<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use GlobalCache;
use Stancl\Tenancy\Tenant;

class GlobalCacheTest extends TestCase
{
    public $autoCreateTenant = false;
    public $autoInitTenancy = false;

    /** @test */
    public function global_cache_manager_stores_data_in_global_cache()
    {
        $this->assertSame(null, cache('foo'));
        GlobalCache::put(['foo' => 'bar'], 1);
        $this->assertSame('bar', GlobalCache::get('foo'));

        Tenant::new()->withDomains(['foo.localhost'])->save();
        tenancy()->init('foo.localhost');
        $this->assertSame('bar', GlobalCache::get('foo'));

        GlobalCache::put(['abc' => 'xyz'], 1);
        cache(['def' => 'ghi'], 10);
        $this->assertSame('ghi', cache('def'));

        tenancy()->endTenancy();
        $this->assertSame('xyz', GlobalCache::get('abc'));
        $this->assertSame('bar', GlobalCache::get('foo'));
        $this->assertSame(null, cache('def'));

        Tenant::new()->withDomains(['bar.localhost'])->save();
        tenancy()->init('bar.localhost');
        $this->assertSame('xyz', GlobalCache::get('abc'));
        $this->assertSame('bar', GlobalCache::get('foo'));
        $this->assertSame(null, cache('def'));
        cache(['def' => 'xxx'], 1);
        $this->assertSame('xxx', cache('def'));

        tenancy()->init('foo.localhost');
        $this->assertSame('ghi', cache('def'));
    }
}
