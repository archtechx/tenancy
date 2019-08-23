<?php

declare(strict_types=1);

namespace Stancl\Tenancy\StorageDrivers;

use Illuminate\Support\Facades\Redis;
use Stancl\Tenancy\Interfaces\StorageDriver;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedException;

class RedisStorageDriver implements StorageDriver
{
    private $redis;

    public function __construct()
    {
        $this->redis = Redis::connection(config('tenancy.redis.connection', 'tenancy'));
    }

    public function identifyTenant(string $domain): array
    {
        $id = $this->getTenantIdByDomain($domain);
        if (! $id) {
            throw new TenantCouldNotBeIdentifiedException($domain);
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
        if (! $fields) {
            return $this->redis->hgetall("tenants:$uuid");
        }

        return \array_combine($fields, $this->redis->hmget("tenants:$uuid", $fields));
    }

    public function getTenantIdByDomain(string $domain): ?string
    {
        return $this->redis->hget("domains:$domain", 'tenant_id') ?: null;
    }

    public function createTenant(string $domain, string $uuid): array
    {
        $this->redis->hmset("domains:$domain", 'tenant_id', $uuid);
        $this->redis->hmset("tenants:$uuid", 'uuid', \json_encode($uuid), 'domain', \json_encode($domain));

        return $this->redis->hgetall("tenants:$uuid");
    }

    /**
     * {@inheritdoc}
     *
     * @param string $id
     * @return bool
     * @todo Make tenant & domain deletion atomic.
     */
    public function deleteTenant(string $id): bool
    {
        try {
            $domain = \json_decode($this->getTenantById($id)['domain']);
        } catch (\Throwable $th) {
            throw new \Exception("No tenant with UUID $id exists.");
        }

        $this->redis->del("domains:$domain");

        return (bool) $this->redis->del("tenants:$id");
    }

    public function getAllTenants(array $uuids = []): array
    {
        $hashes = \array_map(function ($hash) {
            return "tenants:{$hash}";
        }, $uuids);

        if (! $hashes) {
            // Prefix is applied to all functions except scan().
            // This code applies the correct prefix manually.
            $redis_prefix = config('database.redis.options.prefix');

            if (config('database.redis.client') === 'phpredis') {
                $redis_prefix = $this->redis->getOption($this->redis->client()::OPT_PREFIX) ?? $redis_prefix;
            }

            $all_keys = $this->redis->keys('tenants:*');

            $hashes = \array_map(function ($key) use ($redis_prefix) {
                // Left strip $redis_prefix from $key
                return \substr($key, \strlen($redis_prefix));
            }, $all_keys);
        }

        return \array_map(function ($tenant) {
            return $this->redis->hgetall($tenant);
        }, $hashes);
    }

    public function get(string $uuid, string $key)
    {
        return $this->redis->hget("tenants:$uuid", $key);
    }

    public function getMany(string $uuid, array $keys): array
    {
        return $this->redis->hmget("tenants:$uuid", $keys);
    }

    public function put(string $uuid, string $key, $value)
    {
        $this->redis->hset("tenants:$uuid", $key, $value);

        return $value;
    }

    public function putMany(string $uuid, array $values): array
    {
        $this->redis->hmset("tenants:$uuid", $values);

        return $values;
    }
}
