<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\Config;
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
        if (! config('tenancy.redis.tenancy')) {
            $this->markTestSkipped('Redis tenancy disabled.');
        }

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
        $this->assertEquals($old_storage_path . '/' . config('tenancy.filesystem.suffix_base') . tenant('uuid'), $new_storage_path);

        foreach (config('tenancy.filesystem.disks') as $disk) {
            $suffix = config('tenancy.filesystem.suffix_base') . tenant('uuid');
            $current_path_prefix = \Storage::disk($disk)->getAdapter()->getPathPrefix();

            if ($override = config("tenancy.filesystem.root_override.{$disk}")) {
                $correct_path_prefix = \str_replace('%storage_path%', storage_path(), $override);
            } else {
                if ($base = $old_storage_facade_roots[$disk]) {
                    $correct_path_prefix = $base . "/$suffix/";
                } else {
                    $correct_path_prefix = "$suffix/";
                }
            }

            $this->assertSame($correct_path_prefix, $current_path_prefix);
        }
    }

    /** @test */
    public function cache_is_tagged()
    {
        $this->assertSame(['foo'], cache()->tags('foo')->getTags()->getNames());
        $this->initTenancy();

        $expected = [config('tenancy.cache.tag_base') . tenant('uuid'), 'foo', 'bar'];
        $this->assertEquals($expected, cache()->tags(['foo', 'bar'])->getTags()->getNames());
    }
}
