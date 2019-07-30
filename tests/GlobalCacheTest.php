<?php

namespace Stancl\Tenancy\Tests;

use GlobalCache;

class GlobalCacheTest extends TestCase
{
    public $autoCreateTenant = false;
    public $autoInitTenancy = false;

    /** @test */
    public function global_cache_manager_stores_data_in_global_cache()
    {
        dd(app('globalCache'));
        dd(cache());
        $this->assertSame(null, cache('foo'));
        cache(['foo' => 'bar'], 1);
        $this->assertSame('bar', cache('foo'));
        // $this->assertSame('bar', GlobalCache::get('foo'));
        // GlobalCache::put('foo', 'bar');
        dd(GlobalCache::get('foo'));

        tenant()->create('foo.localhost');
        tenancy()->init('foo.localhost');
    }
}