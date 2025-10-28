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
 * By default, this bootstrapper scopes ALL cache stores that use the database driver. If you only
 * want to scope SOME stores, set the static $stores property to an array of names of the stores
 * you want to scope. These stores must use 'database' as their driver.
 *
 * Notably, this bootstrapper sets TenancyServiceProvider::$adjustCacheManagerUsing to a callback
 * that ensures all affected stores still use the central connection when accessed via global cache
 * (typicaly the GlobalCache facade or global_cache() helper).
 */
class DatabaseCacheBootstrapper implements TenancyBootstrapper
{
    /**
     * Cache stores to scope.
     *
     * If null, all cache stores that use the database driver will be scoped.
     * If an array, only the specified stores will be scoped. These all must use the database driver.
     */
    public static array|null $stores = null;

    /**
     * Should scoped stores be adjusted on the global cache manager to use the central connection.
     *
     * You may want to set this to false if you don't use the built-in global cache and instead provide
     * a list of stores to scope (static::$stores), with your own global store excluded that you then
     * use manually. But in such a scenario you likely wouldn't be using global cache at all which means
     * the callbacks for adjusting it wouldn't be executed in the first place.
     */
    public static bool $adjustGlobalCacheManager = true;

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

        $stores = $this->scopedStoreNames();

        foreach ($stores as $storeName) {
            $this->originalConnections[$storeName] = $this->config->get("cache.stores.{$storeName}.connection");
            $this->originalLockConnections[$storeName] = $this->config->get("cache.stores.{$storeName}.lock_connection");

            $this->config->set("cache.stores.{$storeName}.connection", 'tenant');
            $this->config->set("cache.stores.{$storeName}.lock_connection", 'tenant');

            $this->cache->purge($storeName);
        }

        if (static::$adjustGlobalCacheManager) {
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

    protected function scopedStoreNames(): array
    {
        return array_filter(
            static::$stores ?? array_keys($this->config->get('cache.stores', [])),
            function ($storeName) {
                $store = $this->config->get("cache.stores.{$storeName}");

                if (! $store) return false;
                if (! isset($store['driver'])) return false;

                return $store['driver'] === 'database';
            }
        );
    }
}
