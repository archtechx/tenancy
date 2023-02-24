<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Cache;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

class PrefixCacheTenancyBootstrapper implements TenancyBootstrapper
{
    protected string|null $originalPrefix = null;
    public static array $tenantCacheStores = [];

    public function __construct(
        protected ConfigRepository $config,
        protected CacheManager $cacheManager,
    ) {
    }

    public function bootstrap(Tenant $tenant): void
    {
        $this->originalPrefix = $this->config->get('cache.prefix');

        $this->setCachePrefix($this->originalPrefix . $this->config->get('tenancy.cache.prefix_base') . $tenant->getTenantKey());
    }

    public function revert(): void
    {
        $this->setCachePrefix($this->originalPrefix);

        $this->originalPrefix = null;
    }

    protected function setCachePrefix(string|null $prefix): void
    {
        $this->config->set('cache.prefix', $prefix);

        foreach (static::$tenantCacheStores as $driver) {
            // Refresh driver's store to make the driver use the current prefix
            $this->refreshStore($driver);
        }

        // It is needed when a call to the facade has been made before bootstrapping tenancy
        // The facade has its own cache, separate from the container
        Cache::clearResolvedInstances();
    }

    /**
     * Refresh cache driver's store.
     */
    protected function refreshStore(string $driver): void
    {
        $newStore = $this->cacheManager->resolve($driver)->getStore();
        /** @var Repository $repository */
        $repository = $this->cacheManager->driver($driver);

        $repository->setStore($newStore);
    }
}
