<?php

namespace Stancl\Tenancy\StorageDrivers;

use Illuminate\Support\Facades\Redis;
use Stancl\Tenancy\Interfaces\StorageDriver;

class RedisStorageDriver implements StorageDriver
{
    private $redis;

    public function __construct()
    {
        $this->redis = Redis::connection('tenancy');
    }

    public function identifyTenant(string $domain): array
    {
        $id = $this->getTenantIdByDomain($domain);
        if (! $id) {
            throw new \Exception("Tenant could not be identified on domain {$domain}");
        }
        return array_merge(['uuid' => $id], $this->getTenantById($id));
    }

    /**
     * Get information about the tenant based on his uuid.
     *
     * @param string $uuid
     * @param array|string $fields
     * @return array
     */
    public function getTenantById(string $uuid, $fields = []): array
    {
        $fields = (array) $fields;
        
        if (! $fields) {
            return $this->redis->hgetall("tenants:$uuid");
        }

        return array_combine($fields, $this->redis->hmget("tenants:$uuid", $fields));
    }

    public function getTenantIdByDomain(string $domain): ?string
    {
        return $this->redis->hget("domains:$domain", 'tenant_id') ?: null;
    }

    public function createTenant(string $domain, string $uuid): array
    {
        $this->redis->hmset("domains:$domain", 'tenant_id', $uuid);
        $this->redis->hmset("tenants:$uuid", 'uuid', $uuid, 'domain', $domain);
        return $this->redis->hgetall("tenants:$uuid");
    }

    public function deleteTenant(string $id): bool
    {
        try {
            $domain = $this->getTenantById($id)['domain'];
        } catch (\Throwable $th) {
            throw new \Exception("No tenant with UUID $id exists.");
        }

        $this->redis->del("domains:$domain");
        return (bool) $this->redis->del("tenants:$id");
    }

    public function getAllTenants(array $uuids = []): array
    {
        $hashes = array_map(function ($hash) {
            return "tenants:{$hash}";
        }, $uuids);

        $hashes = $hashes ?: $this->redis->scan(null, 'tenants:*');

        return array_map(function ($tenant) {
            return $this->redis->hgetall($tenant);
        }, $hashes);
    }

    public function get(string $uuid, string $key)
    {
        return $this->redis->hget("tenants:$uuid", $key);
    }

    public function put(string $uuid, string $key, $value)
    {
        $this->redis->hset("tenants:$uuid", $key, $value);
        return $value;
    }
}
