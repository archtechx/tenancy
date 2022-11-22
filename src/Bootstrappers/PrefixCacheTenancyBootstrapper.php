<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

class PrefixCacheTenancyBootstrapper implements TenancyBootstrapper
{
    protected null|string $originalPrefix = null;
    protected string $storeName;

    public function __construct(
        protected Application $app
    ) {
    }

    public function bootstrap(Tenant $tenant): void
    {
        $this->originalPrefix = $this->app['config']['cache.prefix'];
        $this->storeName = $this->app['config']['cache.default'];

        $this->setCachePrefix($this->app['config']['tenancy.cache.prefix_base'] . $tenant->id);
    }

    public function revert(): void
    {
        $this->setCachePrefix($this->originalPrefix);
        $this->originalPrefix = null;
    }

    protected function setCachePrefix(null|string $prefix): void
    {
        $this->app['config']['cache.prefix'] = $prefix;

        $this->app['cache']->forgetDriver($this->storeName);

        //This is important because the Cache Repository is using an old version of the CacheManager
        $this->app->forgetInstance('cache.store');

        Cache::clearResolvedInstances();
    }
}
