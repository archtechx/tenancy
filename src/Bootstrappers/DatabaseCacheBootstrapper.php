<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Exception;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\TenancyServiceProvider;

/**
 * This bootstrapper allows cache to be stored in tenant databases by switching the database
 * connection used by cache stores that use the database driver.
 *
 * Can be used instead of CacheTenancyBootstrapper.
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
        if (! config('database.connections.tenant')) {
            throw new Exception('DatabaseCacheBootstrapper must run after DatabaseTenancyBootstrapper.');
        }

        $stores = $this->getDatabaseCacheStores();

        foreach ($stores as $storeName) {
            $this->originalConnections[$storeName] = $this->config->get("cache.stores.{$storeName}.connection");
            $this->originalLockConnections[$storeName] = $this->config->get("cache.stores.{$storeName}.lock_connection");

            $this->config->set("cache.stores.{$storeName}.connection", 'tenant');
            $this->config->set("cache.stores.{$storeName}.lock_connection", 'tenant');

            $this->cache->purge($storeName);
        }

        // Preferably we'd try to respect the original value of this static property -- store it in a variable,
        // pull it into the closure, and execute it there. But such a naive approach would lead to existing callbacks
        // *from here* being executed repeatedly in a loop on reinitialization. For that reason we do not do that
        // (this is our only use of $adjustCacheManagerUsing anyway) but ideally at some point we'd have a better solution.
        $originalConnections = array_combine($stores, array_map(fn (string $storeName) => [
            'connection' => $this->originalConnections[$storeName] ?? config('tenancy.database.central_connection'),
            'lockConnection' => $this->originalLockConnections[$storeName] ?? config('tenancy.database.central_connection'),
        ], $stores));

        TenancyServiceProvider::$adjustCacheManagerUsing = static function (CacheManager $manager) use ($originalConnections) {
            foreach ($originalConnections as $storeName => $connections) {
                /** @var DatabaseStore $store */
                $store = $manager->store($storeName)->getStore();

                $store->setConnection(DB::connection($connections['connection']));
                $store->setLockConnection(DB::connection($connections['lockConnection']));
            }
        };
    }

    public function revert(): void
    {
        foreach ($this->originalConnections as $storeName => $originalConnection) {
            $this->config->set("cache.stores.{$storeName}.connection", $originalConnection);
            $this->config->set("cache.stores.{$storeName}.lock_connection", $this->originalLockConnections[$storeName]);

            $this->cache->purge($storeName);
        }

        TenancyServiceProvider::$adjustCacheManagerUsing = null;
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
