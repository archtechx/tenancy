<?php

namespace Stancl\Tenancy\StorageDrivers;

use Stancl\Tenancy\Tenant;
use Stancl\Tenancy\Interfaces\StorageDriver;

class DatabaseStorageDriver implements StorageDriver
{
    public $useJson = false;

    // todo use an instance of tenant model?
    // todo write tests verifying that data is decoded and added to the array

    public function identifyTenant(string $domain): array // todo returns data col
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
        if ($fields) {
            return Tenant::find($uuid)->only($fields);
        } else {
            return Tenant::find($uuid)->toArray();
        }
    }

    public function getTenantIdByDomain(string $domain): ?string
    {
        return Tenant::where('domain', $domain)->first()->uuid ?? null;
    }

    public function createTenant(string $domain, string $uuid): array
    {
        return Tenant::create(['uuid' => $uuid, 'domain' => $domain])->toArray();
    }

    public function deleteTenant(string $id): bool
    {
        return Tenant::find($id)->delete();
    }

    public function getAllTenants(array $uuids = []): array
    {
        return Tenant::getAllTenants($uuids)->toArray();
    }

    public function get(string $uuid, string $key)
    {
        return Tenant::find($uuid)->get($key);
    }

    public function getMany(string $uuid, array $keys): array
    {
        return Tenant::find($uuid)->getMany($keys);
    }

    public function put(string $uuid, string $key, $value)
    {
        return Tenant::find($uuid)->put($key, $value);
    }

    public function putMany(string $uuid, array $values): array
    {
        foreach ($values as $key => $value) {
            Tenant::find($uuid)->put($key, $value);
        }

        return $values;
    }
}
