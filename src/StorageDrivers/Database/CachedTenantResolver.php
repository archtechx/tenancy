<?php

declare(strict_types=1);

namespace Stancl\Tenancy\StorageDrivers\Database;

use Closure;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;
use Stancl\Tenancy\Tenant;

class CachedTenantResolver
{
    /** @var CacheRepository */
    protected $cache;

    /** @var ConfigRepository */
    protected $config;

    public function __construct(CacheManager $cacheManager, ConfigRepository $config)
    {
        $this->cache = $cacheManager->store($config->get('tenancy.storage_drivers.db.cache_store'));
        $this->config = $config;
    }

    protected function ttl(): int
    {
        return $this->config->get('tenancy.storage_drivers.db.cache_ttl');
    }

    public function getTenantIdByDomain(string $domain, Closure $query): string
    {
        return $this->cache->remember('_tenancy_domain_to_id:' . $domain, $this->ttl(), $query);
    }

    public function findById(string $id, Closure $dataQuery, Closure $domainsQuery): Tenant
    {
        $data = $this->cache->remember('_tenancy_id_to_data:' . $id, $this->ttl(), $dataQuery);
        $domains = $this->cache->remember('_tenancy_id_to_domains:' . $id, $this->ttl(), $domainsQuery);

        return Tenant::fromStorage($data)->withDomains($domains);
    }

    // todo update cache on writes to data & domains
}
