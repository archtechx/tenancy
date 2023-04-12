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

class PrefixCacheTenancyBootstrapper implements TenancyBootstrapper
{
    protected string|null $defaultPrefix = null;
    public static array $originalPrefixes = []; // E.g. 'redis' => 'redis_prefix_' (if not specified, use config('cache.prefix') as the default)
    public static array $tenantCacheStores = []; // E.g. 'redis'
    public static array $prefixGenerators = [
        // driverName => Closure(Tenant $tenant)
    ];

    public function __construct(
        protected ConfigRepository $config,
        protected CacheManager $cacheManager,
    ) {
    }

    public function bootstrap(Tenant $tenant): void
    {
        $this->defaultPrefix = $this->config->get('cache.prefix');

        foreach (static::$tenantCacheStores as $store) {
            static::$originalPrefixes[$store] ??= $this->defaultPrefix;
        }

        foreach (static::$tenantCacheStores as $store) {
            $this->setCachePrefix($store, $this->getStorePrefix($store, $tenant));
        }
    }

    public function revert(): void
    {
        foreach (static::$originalPrefixes as $driver => $prefix) {
            $this->setCachePrefix($driver, $prefix);
        }

        static::$originalPrefixes = [];
    }

    protected function setCachePrefix(string $driver, string|null $prefix): void
    {
        $this->config->set('cache.prefix', $prefix);

        // Refresh driver's store to make the driver use the current prefix
        $this->refreshStore($driver);

        // It is needed when a call to the facade has been made before bootstrapping tenancy
        // The facade has its own cache, separate from the container
        Cache::clearResolvedInstances();

        // Now that the store uses the passed prefix
        // Set the configured prefix back to the default one
        $this->config->set('cache.prefix', $this->defaultPrefix);
    }

    public function getStorePrefix(string $store, Tenant $tenant): string
    {
        if (isset(static::$prefixGenerators[$store])) {
            return static::$prefixGenerators[$store]($tenant);
        }

        return (static::$originalPrefixes[$store] ?? $this->defaultPrefix) . $this->config->get('tenancy.cache.prefix_base') . $tenant->getTenantKey();
    }

    public static function generatePrefixUsing(string $store, Closure $prefixGenerator): void
    {
        static::$prefixGenerators[$store] = $prefixGenerator;
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
