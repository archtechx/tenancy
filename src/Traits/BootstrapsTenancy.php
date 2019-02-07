<?php

namespace Stancl\Tenancy\Traits;

use Stancl\Tenancy\CacheManager;
use Illuminate\Support\Facades\Redis;

trait BootstrapsTenancy
{
    public $oldStoragePaths;

    public function bootstrap()
    {
        $this->switchDatabaseConnection();
        $this->setPhpRedisPrefix($this->app['config']['tenancy.redis.prefixed_connections']);
        $this->tagCache();
        $this->suffixFilesystemRootPaths();
    }

    public function switchDatabaseConnection()
    {
        $this->database->connect($this->getDatabaseName());
    }

    public function setPhpRedisPrefix($connections = ['default'])
    {
        foreach ($connections as $connection) {
            $prefix = config('tenancy.redis.prefix_base') . $this->tenant['uuid'];
            $client = Redis::connection($connection)->client();
            $client->setOption($client::OPT_PREFIX, $prefix);
        }
    }

    public function tagCache()
    {
        $this->app->extend('cache', function () {
            return new CacheManager($this->app);
        });
    }

    public function suffixFilesystemRootPaths()
    {
        $old = [
            "storage_disks" => [],
            "storage_path" => $this->app->storagePath(),
        ];

        $suffix = $this->app['config']['tenancy.filesystem.suffix_base'] . tenant('uuid');

        // Storage facade
        foreach ($this->app['config']['tenancy.filesystem.disks'] as $disk) {
            $root = $this->app['config']["filesystems.disks.{$disk}.root"];

            \Storage::disk($disk)->getAdapter()->setPathPrefix(
                $root . "/{$suffix}"
            );

            $old['storage_disks'][$disk] = $root;
        }

        // storage_path()
        $this->app->useStoragePath($this->app->storagePath() . "/{$suffix}");

        $this->oldStoragePaths = $old;
    }
}
