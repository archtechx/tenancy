<?php

declare(strict_types=1);

namespace Stancl\Tenancy\TenancyBoostrappers;

use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Tenant;

class CacheTenancyBoostrapper implements TenancyBootstrapper
{
    /** @var \Illuminate\Cache\CacheManager */
    protected $originalCache;

    public function start(Tenant $tenant)
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
