<?php

declare(strict_types=1);

namespace Stancl\Tenancy\StorageDrivers\Database;

use Closure;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;

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

    public function getTenantIdByDomain(string $domain, Closure $query): ?string
    {
        return $this->cache->remember('_tenancy_domain_to_id:' . $domain, $this->ttl(), $query);
    }

    public function getDataById(string $id, Closure $dataQuery): ?array
    {
        return $this->cache->remember('_tenancy_id_to_data:' . $id, $this->ttl(), $dataQuery);
    }

    public function getDomainsById(string $id, Closure $domainsQuery): ?array
    {
        return $this->cache->remember('_tenancy_id_to_domains:' . $id, $this->ttl(), $domainsQuery);
    }

    public function invalidateTenant(string $id): void
    {
        $this->invalidateTenantData($id);
        $this->invalidateTenantDomains($id);
    }

    public function invalidateTenantData(string $id): void
    {
        $this->cache->forget('_tenancy_id_to_data:' . $id);
    }

    public function invalidateTenantDomains(string $id): void
    {
        $this->cache->forget('_tenancy_id_to_domains:' . $id);
    }

    public function invalidateDomainToIdMapping(array $domains): void
    {
        foreach ($domains as $domain) {
            $this->cache->forget('_tenancy_domain_to_id:' . $domain);
        }
    }
}
