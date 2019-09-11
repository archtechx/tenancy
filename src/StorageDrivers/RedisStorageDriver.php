<?php

declare(strict_types=1);

namespace Stancl\Tenancy\StorageDrivers;

use Illuminate\Foundation\Application;
use Stancl\Tenancy\Interfaces\StorageDriver;
use Illuminate\Contracts\Redis\Factory as Redis;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedException;
use Stancl\Tenancy\Tenant;

class RedisStorageDriver implements StorageDriver
{
    /** @var Application */
    protected $app;

    /** @var Redis */
    protected $redis;

    public function __construct(Application $app, Redis $redis)
    {
        $this->app = $app;
        $this->redis = $redis->connection($app['config']['tenancy.redis.connection'] ?? 'tenancy');
    }

    /**
     * Get the current tenant.
     *
     * @return Tenant
     */
    protected function tenant()
    {
        return $this->app[Tenant::class];
    }

    public function findByDomain(string $domain): Tenant
    {
        $id = $this->getTenantIdByDomain($domain);
        if (! $id) {
            throw new TenantCouldNotBeIdentifiedException($domain);
        }

        return $this->find($id);
    }

    /**
     * Get information about the tenant based on his id.
     *
     * @param string $id
     * @param string[] $fields
     * @return array
     */
    public function find(string $id, array $fields = []): array
    {
        if (! $fields) {
            return $this->redis->hgetall("tenants:$id");
        }

        return array_combine($fields, $this->redis->hmget("tenants:$id", $fields));
    }

    public function getTenantIdByDomain(string $domain): ?string
    {
        return $this->redis->hget("domains:$domain", 'tenant_id') ?: null;
    }

    public function createTenant(Tenant $tenant): void
    {
        $id = $tenant->id;

        foreach ($tenant->domains as $domain) {
            $this->redis->hmset("domains:$domain", 'tenant_id', $id);
        }
        $this->redis->hmset("tenants:$id", 'id', json_encode($id), 'domain', json_encode($domain));

        return $this->redis->hgetall("tenants:$id"); // todo
    }

    /** @todo Make tenant & domain deletion atomic. */
    public function deleteTenant(Tenant $tenant): void
    {
        foreach ($tenant->domains as $domain) {
            $this->redis->del("domains:$domain");
        }

        $this->redis->del("tenants:{$tenant->id}");
    }

    public function getAllTenants(array $uuids = []): array
    {
        $hashes = array_map(function ($hash) {
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

            $hashes = array_map(function ($key) use ($redis_prefix) {
                // Left strip $redis_prefix from $key
                return substr($key, strlen($redis_prefix));
            }, $all_keys);
        }

        return array_map(function ($tenant) {
            return $this->redis->hgetall($tenant);
        }, $hashes);
    }

    public function get(string $uuid, string $key)
    {
        return json_decode($this->redis->hget("tenants:$uuid", $key), true);
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
