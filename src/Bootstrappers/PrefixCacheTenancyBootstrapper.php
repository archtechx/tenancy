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
    protected array $originalPrefixes = []; // E.g. 'redis' => 'redis_prefix_'
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
        // If the user didn't specify the default generator
        // Use static::defaultPrefixGenerator() as the default prefix generator
        if (! isset(static::$prefixGenerators['default'])) {
            static::generatePrefixUsing('default', static::defaultPrefixGenerator($this->config->get('cache.prefix')));
        }

        foreach (static::$tenantCacheStores as $store) {
            $this->originalPrefixes[$store] = $this->config->get('cache.prefix');

            $this->setCachePrefix($store, $this->getStorePrefix($store, $tenant));
        }
    }

    public function revert(): void
    {
        foreach ($this->originalPrefixes as $driver => $prefix) {
            $this->setCachePrefix($driver, $prefix);
        }

        $this->originalPrefixes = [];
    }

    public static function defaultPrefixGenerator(string $originalPrefix = ''): Closure
    {
        return function (Tenant $tenant) use ($originalPrefix) {
            return $originalPrefix . config('tenancy.cache.prefix_base') . $tenant->getTenantKey();
        };
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

    public function getStorePrefix(string $store, Tenant $tenant): string
    {
        if (isset(static::$prefixGenerators[$store])) {
            return static::$prefixGenerators[$store]($tenant);
        }

        // Use default generator if the store doesn't have a custom generator
        return static::$prefixGenerators['default']($tenant);
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
