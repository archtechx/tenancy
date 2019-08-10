<?php

namespace Stancl\Tenancy\StorageDrivers;

use Stancl\Tenancy\Interfaces\TenantModel;
use Stancl\Tenancy\Interfaces\StorageDriver;

class DatabaseStorageDriver implements StorageDriver
{
    public function __construct(TenantModel $tenant)
    {
        $this->tenant = $tenant;
    }

    public function identifyTenant(string $domain): array
    {
        $id = $this->getTenantIdByDomain($domain);
        if (! $id) {
            throw new \Exception("Tenant could not be identified on domain {$domain}");
        }

        return $this->getTenantById($id);
    }

    /**
     * Get information about the tenant based on his uuid.
     *
     * @param string $uuid
     * @param array $fields
     * @return array
     */
    public function getTenantById(string $uuid, array $fields = []): array
    {
        return $this->tenant->find($uuid)->only($fields);
    }

    public function getTenantIdByDomain(string $domain): ?string
    {
        return $this->tenant->where('domain', $domain)->first()->uuid ?? null;
    }

    public function createTenant(string $domain, string $uuid): array
    {
        return $this->tenant->create(['uuid' => $uuid, 'domain' => $domain])->toArray();
    }

    public function deleteTenant(string $id): bool
    {
        return $this->tenant->find($id)->delete();
    }

    public function getAllTenants(array $uuids = []): array
    {
        return $this->tenant->all()->map(function ($model) {
            return $model->toArray();
        })->toArray();
    }

    public function get(string $uuid, string $key)
    {
        return $this->tenant->find($uuid)->get($key);
    }

    public function getMany(string $uuid, array $keys): array
    {
        return $this->tenant->getMany($keys);
    }

    public function put(string $uuid, string $key, $value)
    {
        return $this->tenant->find($uuid)->put($key, $value);
    }

    public function putMany(string $uuid, array $values): array
    {
        foreach ($values as $key => $value) {
            $this->tenant->find($uuid)->put($key, $value);
        }

        return $values;
    }
}
