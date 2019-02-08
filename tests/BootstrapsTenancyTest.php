<?php

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\Redis;

class BootstrapsTenancyTest extends TestCase
{
    public $autoInitTenancy = false;

    /** @test */
    public function database_connection_is_switched()
    {
        $old_connection_name = app(\Illuminate\Database\DatabaseManager::class)->connection()->getName();
        $this->initTenancy();
        $new_connection_name = app(\Illuminate\Database\DatabaseManager::class)->connection()->getName();

        $this->assertNotEquals($old_connection_name, $new_connection_name);
        $this->assertEquals('tenant', $new_connection_name);
    }

    /** @test */
    public function redis_is_prefixed()
    {
        $this->initTenancy();
        foreach (config('tenancy.redis.prefixed_connections', ['default']) as $connection) {
            $prefix = config('tenancy.redis.prefix_base') . tenant('uuid');
            $client = Redis::connection($connection)->client();
            $this->assertEquals($prefix, $client->getOption($client::OPT_PREFIX));
        }
    }

    /** @test */
    public function filesystem_is_suffixed()
    {
        $old_storage_path = storage_path();
        $old_storage_facade_roots = [];
        foreach (config('tenancy.filesystem.disks') as $disk) {
            $old_storage_facade_roots[$disk] = config("filesystems.disks.{$disk}.root");
        }

        $this->initTenancy();
        
        $new_storage_path = storage_path();
        $this->assertEquals($old_storage_path . "/" . config('tenancy.filesystem.suffix_base') . tenant('uuid'), $new_storage_path);

        foreach (config('tenancy.filesystem.disks') as $disk) {
            $suffix = config('tenancy.filesystem.suffix_base') . tenant('uuid');
            $current_path_prefix = \Storage::disk($disk)->getAdapter()->getPathPrefix();
            $this->assertSame($old_storage_facade_roots[$disk] . "/$suffix/", $current_path_prefix);
        }
    }

    /** @test */
    public function cache_is_tagged()
    {
        $this->markTestIncomplete('see BootstrapsTenancyTest@cache_is_tagged');
        // todo check that tags are set
        // doesn't seem to be possible right now? can't find a way to get TaggedCache's tags
    }
}
