<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Config\Repository as RepositoryContract;
use Illuminate\Support\Facades\Cache;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

class PrefixCacheTenancyBootstrapper implements TenancyBootstrapper
{
    protected string|null $originalPrefix = null;
    protected string $storeName;
    public static array $tenantCacheStores = [];

    public function __construct(
        protected RepositoryContract $config,
        protected CacheManager $cacheManager,
    ) {
    }

    public function bootstrap(Tenant $tenant): void
    {
        $this->originalPrefix = $this->config->get('cache.prefix');
        $this->storeName = $this->config->get('cache.default');

        if (in_array($this->storeName, static::$tenantCacheStores)) {
            $this->setCachePrefix($this->originalPrefix . $this->config->get('tenancy.cache.prefix_base') . $tenant->getTenantKey());
        }
    }

    public function revert(): void
    {
        $this->setCachePrefix($this->originalPrefix);
        $this->originalPrefix = null;
    }

    protected function setCachePrefix(string|null $prefix): void
    {
        $this->config->set('cache.prefix', $prefix);

        $newStore = $this->cacheManager->resolve($this->storeName ?? $this->cacheManager->getDefaultDriver())->getStore();

        /** @var Repository $repository */
        $repository = $this->cacheManager->driver($this->storeName);

        $repository->setStore($newStore);

        // It is needed when a call to the facade has been made before bootstrapping tenancy
        // The facade has its own cache, separate from the container
        Cache::clearResolvedInstances();
    }
}
