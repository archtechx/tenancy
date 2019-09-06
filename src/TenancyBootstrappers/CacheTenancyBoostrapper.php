<?php

namespace Stancl\Tenancy\TenancyBoostrappers;

use Stancl\Tenancy\Contracts\TenancyBootstrapper;

class CacheTenancyBootstrapped implements TenancyBootstrapper
{
    /** @var \Illuminate\Cache\CacheManager */
    protected $originalCache;

    public function start()
    {
        $this->originalCache = $this->originalCache ?? $this->app['cache'];
        $this->app->extend('cache', function () {
            return new CacheManager($this->app);
        });
    }

    public function end()
    {
        $this->app->extend('cache', function () {
            return $this->originalCache;
        });
    }
}