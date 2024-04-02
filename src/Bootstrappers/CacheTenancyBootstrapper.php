<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Closure;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Cache;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Makes cache tenant-aware by applying a prefix.
 */
class CacheTenancyBootstrapper implements TenancyBootstrapper
{
    public static Closure|null $prefixGenerator = null;

    protected string|null $originalPrefix = null;

    public function __construct(
        protected ConfigRepository $config,
        protected CacheManager $cacheManager,
    ) {
    }

    public function bootstrap(Tenant $tenant): void
    {
        $this->originalPrefix = $this->config->get('cache.prefix');

        $prefix = $this->generatePrefix($tenant);

        foreach ($this->config->get('tenancy.cache.stores') as $store) {
            $this->setCachePrefix($store, $prefix);

            // Now that the store uses the passed prefix
            // Set the configured prefix back to the default one
            $this->config->set('cache.prefix', $this->originalPrefix);
        }
    }

    public function revert(): void
    {
        foreach ($this->config->get('tenancy.cache.stores') as $store) {
            $this->setCachePrefix($store, $this->originalPrefix);
        }
    }

    protected function setCachePrefix(string $driver, string|null $prefix): void
    {
        $this->config->set('cache.prefix', $prefix);

        // Refresh driver's store to make the driver use the current prefix
        $this->refreshStore($driver);

        // It is needed when a call to the facade has been made before bootstrapping tenancy
        // The facade has its own cache, separate from the container
        Cache::clearResolvedInstances();
    }

    public function generatePrefix(Tenant $tenant): string
    {
        $defaultPrefix = $this->originalPrefix . $this->config->get('tenancy.cache.prefix_base') . $tenant->getTenantKey();

        return static::$prefixGenerator ? (static::$prefixGenerator)($tenant) : $defaultPrefix;
    }

    public static function generatePrefixUsing(Closure $prefixGenerator): void
    {
        static::$prefixGenerator = $prefixGenerator;
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
