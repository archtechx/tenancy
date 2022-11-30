<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

class PrefixCacheTenancyBootstrapper implements TenancyBootstrapper
{
    protected null|string $originalPrefix = null;
    protected string $storeName;

    public function __construct(
        protected Application $app,
        protected Repository $config,
    ) {
    }

    public function bootstrap(Tenant $tenant): void
    {
        $this->originalPrefix = $this->config->get('cache.prefix');
        $this->storeName = $this->config->get('cache.default');

        $this->setCachePrefix($this->originalPrefix . $this->config->get('tenancy.cache.prefix_base') . $tenant->getTenantKey());
    }

    public function revert(): void
    {
        $this->setCachePrefix($this->originalPrefix);
        $this->originalPrefix = null;
    }

    protected function setCachePrefix(null|string $prefix): void
    {
        $this->config->set('cache.prefix', $prefix);

        $this->app['cache']->forgetDriver($this->storeName);

        // The CacheManager will have the $app['config'] array cached with old prefixes on the 'cache' instance
        // This call will forget the 'cache' instance
        $this->app->forgetInstance('cache');

        // The Cache Repository is using an old version of the CacheManager so we need to forget it
        $this->app->forgetInstance('cache.store');

        // Forget the cache repository in the container to cover some edge-cases
        $this->app->forgetInstance(CacheRepository::class);

        // It is needed when a call to the facade has been made before bootstrapping tenancy
        // The facade has its own cache, separate from the container
        Cache::clearResolvedInstances();
    }
}
