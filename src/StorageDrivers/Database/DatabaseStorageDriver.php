<?php

declare(strict_types=1);

namespace Stancl\Tenancy\StorageDrivers\Database;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Contracts\Future\CanDeleteKeys;
use Stancl\Tenancy\Contracts\Future\CanFindByAnyKey;
use Stancl\Tenancy\Contracts\StorageDriver;
use Stancl\Tenancy\DatabaseManager;
use Stancl\Tenancy\Exceptions\DomainsOccupiedByOtherTenantException;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedException;
use Stancl\Tenancy\Exceptions\TenantDoesNotExistException;
use Stancl\Tenancy\Exceptions\TenantWithThisIdAlreadyExistsException;
use Stancl\Tenancy\Tenant;

class DatabaseStorageDriver implements StorageDriver, CanDeleteKeys, CanFindByAnyKey
{
    /** @var Application */
    protected $app;

    /** @var Connection */
    protected $centralDatabase;

    /** @var TenantRepository */
    protected $tenants;

    /** @var DomainRepository */
    protected $domains;

    /** @var CachedTenantResolver */
    protected $cache;

    /** @var Tenant The default tenant. */
    protected $tenant;

    public function __construct(Application $app, ConfigRepository $config, CachedTenantResolver $cache)
    {
        $this->app = $app;
        $this->cache = $cache;
        $this->centralDatabase = $this->getCentralConnection();
        $this->tenants = new TenantRepository($config);
        $this->domains = new DomainRepository($config);
    }

    /**
     * Get the central database connection.
     *
     * @return Connection
     */
    public static function getCentralConnection(): Connection
    {
        return DB::connection(static::getCentralConnectionName());
    }

    public static function getCentralConnectionName(): string
    {
        return config('tenancy.storage_drivers.db.connection') ?? app(DatabaseManager::class)->originalDefaultConnectionName;
    }

    public function findByDomain(string $domain): Tenant
    {
        $query = function () use ($domain) {
            return $this->domains->getTenantIdByDomain($domain);
        };

        if ($this->usesCache()) {
            $id = $this->cache->getTenantIdByDomain($domain, $query);
        } else {
            $id = $query();
        }

        if (! $id) {
            throw new TenantCouldNotBeIdentifiedException($domain);
        }

        return $this->findById($id);
    }

    public function findById(string $id): Tenant
    {
        $dataQuery = function () use ($id) {
            $data = $this->tenants->find($id);

            return $data ? $this->tenants->decodeData($data) : null;
        };
        $domainsQuery = function () use ($id) {
            return $this->domains->getTenantDomains($id);
        };

        if ($this->usesCache()) {
            $data = $this->cache->getDataById($id, $dataQuery);
            $domains = $this->cache->getDomainsById($id, $domainsQuery);
        } else {
            $data = $dataQuery();
            $domains = $domainsQuery();
        }

        if (! $data) {
            throw new TenantDoesNotExistException($id);
        }

        return Tenant::fromStorage($data)
            ->withDomains($domains);
    }

    /**
     * Find a tenant using an arbitrary key.
     *
     * @param string $key
     * @param mixed $value
     * @return Tenant
     * @throws TenantDoesNotExistException
     */
    public function findBy(string $key, $value): Tenant
    {
        $tenant = $this->tenants->findBy($key, $value);

        if (! $tenant) {
            throw new TenantDoesNotExistException($value, $key);
        }

        return Tenant::fromStorage($this->tenants->decodeData($tenant))
            ->withDomains($this->domains->getTenantDomains($tenant['id']));
    }

    public function ensureTenantCanBeCreated(Tenant $tenant): void
    {
        if ($this->tenants->exists($tenant)) {
            throw new TenantWithThisIdAlreadyExistsException($tenant->id);
        }

        if ($this->domains->occupied($tenant->domains)) {
            throw new DomainsOccupiedByOtherTenantException;
        }
    }

    public function withDefaultTenant(Tenant $tenant): self
    {
        $this->tenant = $tenant;

        return $this;
    }

    public function createTenant(Tenant $tenant): void
    {
        $this->centralDatabase->transaction(function () use ($tenant) {
            $this->tenants->insert($tenant);
            $this->domains->insertTenantDomains($tenant);
        });
    }

    public function updateTenant(Tenant $tenant): void
    {
        $originalDomains = $this->domains->getTenantDomains($tenant);

        $this->centralDatabase->transaction(function () use ($tenant, $originalDomains) {
            $this->tenants->updateTenant($tenant);

            $this->domains->updateTenantDomains($tenant, $originalDomains);
        });

        if ($this->usesCache()) {
            $this->cache->invalidateTenant($tenant->id);
            $this->cache->invalidateDomainToIdMapping($originalDomains);
        }
    }

    public function deleteTenant(Tenant $tenant): void
    {
        $originalDomains = $this->domains->getTenantDomains($tenant);

        $this->centralDatabase->transaction(function () use ($tenant) {
            $this->tenants->where('id', $tenant->id)->delete();
            $this->domains->where('tenant_id', $tenant->id)->delete();
        });

        if ($this->usesCache()) {
            $this->cache->invalidateTenant($tenant->id);
            $this->cache->invalidateDomainToIdMapping($originalDomains);
        }
    }

    /**
     * Get all tenants.
     *
     * @param string[] $ids
     * @return Tenant[]
     */
    public function all(array $ids = []): array
    {
        return $this->tenants->all($ids)->map(function ($data) {
            return Tenant::fromStorage($data)
                ->withDomains($this->domains->getTenantDomains($data['id']));
        })->toArray();
    }

    /**
     * Get the current tenant.
     *
     * @return Tenant
     */
    protected function currentTenant()
    {
        return $this->tenant ?? $this->app[Tenant::class];
    }

    public function get(string $key, Tenant $tenant = null)
    {
        return $this->tenants->get($key, $tenant ?? $this->currentTenant());
    }

    public function getMany(array $keys, Tenant $tenant = null): array
    {
        return $this->tenants->getMany($keys, $tenant ?? $this->currentTenant());
    }

    public function put(string $key, $value, Tenant $tenant = null): void
    {
        $tenant = $tenant ?? $this->currentTenant();
        $this->tenants->put($key, $value, $tenant);

        if ($this->usesCache()) {
            $this->cache->invalidateTenantData($tenant->id);
        }
    }

    public function putMany(array $kvPairs, Tenant $tenant = null): void
    {
        $tenant = $tenant ?? $this->currentTenant();
        $this->tenants->putMany($kvPairs, $tenant);

        if ($this->usesCache()) {
            $this->cache->invalidateTenantData($tenant->id);
        }
    }

    public function deleteMany(array $keys, Tenant $tenant = null): void
    {
        $tenant = $tenant ?? $this->currentTenant();
        $this->tenants->deleteMany($keys, $tenant);

        if ($this->usesCache()) {
            $this->cache->invalidateTenantData($tenant->id);
        }
    }

    public function usesCache(): bool
    {
        return $this->app['config']['tenancy.storage_drivers.db.cache_store'] !== null;
    }
}
