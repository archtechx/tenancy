<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Illuminate\Config\Repository;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;
use Illuminate\Cache\CacheManager;

class DatabaseCacheBootstrapper implements TenancyBootstrapper
{
    public function __construct(
        protected Repository $config,
        protected CacheManager $cache,
        protected string|null $originalConnection = null,
    ) {}

    public function bootstrap(Tenant $tenant): void
    {
        $this->originalConnection = $this->config->get('cache.stores.database.connection');

        $this->config->set('cache.stores.database.connection', 'tenant');

        $this->cache->purge('database');
    }

    public function revert(): void
    {
        $this->config->set('cache.stores.database.connection', $this->originalConnection);

        $this->cache->purge('database');
    }
}
