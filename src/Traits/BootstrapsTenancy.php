<?php

namespace Stancl\Tenancy;

trait BootstrapsTenancy
{
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
        return;
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
        $suffix = $this->app['config']['tenancy.filesystem.suffix_base'] . tenant('uuid');
        foreach ($this->app['config']['tenancy.filesystem.disks'] as $disk) {
            \Storage::disk($disk)->getAdapter()->setPathPrefix(
                $this->app['config']["filesystems.disks.{$disk}.root"] . "/{$suffix}"
            );
        }
    }
}
