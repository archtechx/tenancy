<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * This bootstrapper allows cache to be stored in the tenant databases by switching
 * the database cache store's (and cache locks) connection.
 *
 * Intended to be used with database driver-based cache stores, instead of CacheTenancyBootstrapper.
 *
 * On bootstrap(), all database cache stores' connections are set to 'tenant'
 * and the database cache stores are purged from the CacheManager's resolved stores.
 * This forces the manager to resolve new instances of the database stores created with the 'tenant' DB connection on the next cache operation.
 *
 * On revert(), the cache stores' connections are reverted to the originally used ones (usually 'central'), and again,
 * the database cache stores are purged from the CacheManager's resolved stores so that the originally used ones are resolved on the next cache operation.
 */
class DatabaseCacheBootstrapper implements TenancyBootstrapper
{
    /**
     * Cache stores to process. If null, all stores with 'database' driver will be processed.
     * If array, only the specified stores will be processed (with driver validation).
     */
    public static array|null $stores = null;

    public function __construct(
        protected Repository $config,
        protected CacheManager $cache,
        protected array $originalConnections = [],
        protected array $originalLockConnections = []
    ) {}

    public function bootstrap(Tenant $tenant): void
    {
        $stores = $this->getDatabaseCacheStores();

        foreach ($stores as $storeName) {
            $this->originalConnections[$storeName] = $this->config->get("cache.stores.{$storeName}.connection");
            $this->originalLockConnections[$storeName] = $this->config->get("cache.stores.{$storeName}.lock_connection");

            $this->config->set("cache.stores.{$storeName}.connection", 'tenant');
            $this->config->set("cache.stores.{$storeName}.lock_connection", 'tenant');

            $this->cache->purge($storeName);
        }
    }

    public function revert(): void
    {
        foreach ($this->originalConnections as $storeName => $originalConnection) {
            $this->config->set("cache.stores.{$storeName}.connection", $originalConnection);
            $this->config->set("cache.stores.{$storeName}.lock_connection", $this->originalLockConnections[$storeName]);

            $this->cache->purge($storeName);
        }
    }

    /**
     * Get the names of cache stores that use the database driver.
     */
    protected function getDatabaseCacheStores(): array
    {
        // Get all stores specified in the static $stores property.
        // If they don't have the database driver, ignore them.
        if (static::$stores !== null) {
            return array_filter(static::$stores, function ($storeName) {
                $store = $this->config->get("cache.stores.{$storeName}");

                return $store && ($store['driver'] ?? null) === 'database';
            });
        }

        // Get all stores with database driver if $stores is null
        return array_keys(array_filter($this->config->get('cache.stores', []), function ($store) {
            return ($store['driver'] ?? null) === 'database';
        }));
    }
}
