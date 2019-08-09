<?php

namespace Stancl\Tenancy\StorageDrivers;

use Stancl\Tenancy\Interfaces\StorageDriver;
use Stancl\Tenancy\Tenant;

class DatabaseStorageDriver implements StorageDriver
{
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
        $fields = (array) $fields;

        if (! $fields) {
            return $this->redis->hgetall("tenants:$uuid");
        }

        return array_combine($fields, $this->redis->hmget("tenants:$uuid", $fields));
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
        try {
            $domain = json_decode($this->getTenantById($id)['domain']);
        } catch (\Throwable $th) {
            throw new \Exception("No tenant with UUID $id exists.");
        }

        return Tenant::find($id)->delete();
    }

    public function getAllTenants(array $uuids = []): array
    {
        $hashes = array_map(function ($hash) {
            return "tenants:{$hash}";
        }, $uuids);

        return Tenant::all()->map(function ($model) {
            return $model->toArray();
        })->toArray();
    }

    public function get(string $uuid, string $key)
    {
        $tenant = Tenant::find($uuid);
        return $tenant->$key ?? json_decode($tenant->data)[$key] ?? null;
    }

    public function getMany(string $uuid, array $keys): array
    {
        // todo move this logic to the model?
        $tenant = Tenant::find($uuid);
        $tenant_data = null; // cache - json_decode() can be expensive
        $get_from_tenant_data = function ($key) use ($tenant, &$tenant_data) {
            $tenant_data = $tenant_data ?? json_decode($tenant->data);
            return $tenant_data[$key] ?? null;
        };

        return array_reduce($keys, function ($keys, $key) use ($tenant, $get_from_tenant_data) {
            $keys[$key] = $tenant->$key ?? $get_from_tenant_data($key) ?? null;
            return $keys;
        }, []);
    }

    public function put(string $uuid, string $key, $value)
    {
        // return Tenant::find($uuid) TODO
    }

    public function putMany(string $uuid, array $values): array
    {
        // todo
        // $this->redis->hmset("tenants:$uuid", $values);

        return $values;
    }
}
