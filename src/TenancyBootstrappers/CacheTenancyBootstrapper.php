<?php

declare(strict_types=1);

namespace Stancl\Tenancy\TenancyBootstrappers;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Foundation\Application;
use Stancl\Tenancy\CacheManager as TenantCacheManager;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Tenant;

class CacheTenancyBootstrapper implements TenancyBootstrapper
{
    /** @var CacheManager */
    protected $originalCache;

    /** @var Application */
    protected $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function start(Tenant $tenant)
    {
        $this->originalCache = $this->originalCache ?? $this->app['cache'];
        $this->app->extend('cache', function () {
            return new TenantCacheManager($this->app);
        });
    }

    public function end()
    {
        $this->app->extend('cache', function () {
            return $this->originalCache;
        });

        $this->originalCache = null;
    }
}
