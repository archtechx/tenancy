<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Cache;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

class PrefixCacheTenancyBootstrapper implements TenancyBootstrapper
{
    protected string|null $originalPrefix = null;
    protected string $storeName;

    public function __construct(
        protected Repository $config,
        protected CacheManager $cacheManager,
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

    protected function syncStore(): void
    {
        $originalRepository = $this->cacheManager->driver($this->storeName);

        // Delete the repository from CacheManager's $stores cache
        // So that it's forced to resolve the repository again on the next attempt to get it
        $this->cacheManager->forgetDriver($this->storeName);

        // Let CacheManager create a repository with a fresh store
        // To get a new store that uses the current value of `config('cache.prefix')` as the prefix
        $newRepository = $this->cacheManager->driver($this->storeName);

        // Give the new store to the old repository
        $originalRepository->setStore($newRepository->getStore());

        // Overwrite the new repository with the modified old one
        $this->cacheManager->setStore($this->storeName, $originalRepository);
    }

    protected function setCachePrefix(string|null $prefix): void
    {
        $this->config->set('cache.prefix', $prefix);

        $this->syncStore();

        // It is needed when a call to the facade has been made before bootstrapping tenancy
        // The facade has its own cache, separate from the container
        Cache::clearResolvedInstances();
    }
}
