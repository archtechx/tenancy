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
 * Intended to be used with the 'database' cache store, instead of CacheTenancyBootstrapper.
 *
 * On bootstrap(), the database cache store's connection is set to 'tenant'
 * and the database cache store is purged from the CacheManager's resolved stores.
 * This forces the manager to resolve a new instance of the database store created with the 'tenant' DB connection on the next cache operation.
 *
 * On revert(), the cache store's connection is reverted to the originally used one (usually 'central'), and again,
 * the database cache store is purged from the CacheManager's resolved stores so that the originally used one is resolved on the next cache operation.
 */
class DatabaseCacheBootstrapper implements TenancyBootstrapper
{
    public function __construct(
        protected Repository $config,
        protected CacheManager $cache,
        protected string|null $originalConnection = null,
        protected string|null $originalLockConnection = null
    ) {}

    public function bootstrap(Tenant $tenant): void
    {
        $this->originalConnection = $this->config->get('cache.stores.database.connection');
        $this->originalLockConnection = $this->config->get('cache.stores.database.lock_connection');

        $this->config->set('cache.stores.database.connection', 'tenant');
        $this->config->set('cache.stores.database.lock_connection', 'tenant');

        $this->cache->purge('database');
    }

    public function revert(): void
    {
        $this->config->set('cache.stores.database.connection', $this->originalConnection);
        $this->config->set('cache.stores.database.lock_connection', $this->originalLockConnection);

        $this->cache->purge('database');
    }
}
