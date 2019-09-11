<?php

declare(strict_types=1);

namespace Stancl\Tenancy\StorageDrivers;

use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Foundation\Application;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedException;
use Stancl\Tenancy\Contracts\StorageDriver;
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

    public function find(string $id): Tenant
    {
        // todo https://redis.io/commands/hgetall
        if (! $fields) {
            return $this->redis->hgetall("tenants:$id");
        }

        return array_combine($fields, $this->redis->hmget("tenants:$id", $fields)); // todo factory
    }

    public function getTenantIdByDomain(string $domain): ?string
    {
        return $this->redis->hget("domains:$domain", 'tenant_id') ?: null;
    }

    /** @todo make this atomic */
    public function createTenant(Tenant $tenant): void
    {
        $this->redis->pipeline(function ($pipe) use ($tenant) {
            $id = $tenant->id;

            foreach ($tenant->domains as $domain) {
                $pipe->hmset("domains:$domain", 'tenant_id', $id);
            }
            $pipe->hmset("tenants:$id", 'id', json_encode($id), 'domain', json_encode($domain));
        });
    }

    public function updateTenant(Tenant $tenant): void
    {
        $this->redis->pipeline(function ($pipe) use ($tenant) {
            $pipe->hmset("tenants:{$tenant->id}", $tenant->data);

            foreach ($tenant->domains as $domain) {
                $pipe->hmset("domains:$domain", 'tenant_id', $tenant->id);
            }

            // todo deleted domains
        });
    }

    public function deleteTenant(Tenant $tenant): void
    {
        $this->redis->pipeline(function ($pipe) use ($tenant) {
            foreach ($tenant->domains as $domain) {
                $pipe->del("domains:$domain");
            }

            $pipe->del("tenants:{$tenant->id}");
        });
    }

    public function all(array $ids = []): array
    {
        // todo $this->redis->pipeline() - return?
        $hashes = array_map(function ($hash) {
            return "tenants:{$hash}";
        }, $ids);

        if (! $hashes) {
            // Prefix is applied to all functions except scan().
            // This code applies the correct prefix manually.
            $redis_prefix = $this->redis->getOption($this->redis->client()::OPT_PREFIX);

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

    public function get(string $key, Tenant $tenant = null)
    {
        $tenant = $tenant ?? $this->tenant();

        return json_decode($this->redis->hget("tenants:{$tenant->id}", $key), true);
    }

    public function getMany(array $keys, Tenant $tenant = null): array
    {
        $tenant = $tenant ?? $this->tenant();

        $result = [];
        $values = $this->redis->hmget("tenants:{$tenant->id}", $keys);
        foreach ($keys as $i => $key) {
            $result[$key] = $values[$i];
        }

        return $result;
    }

    public function put(string $key, $value, Tenant $tenant = null)
    {
        $tenant = $tenant ?? $this->tenant();
        $this->redis->hset("tenants:{$tenant->id}", $key, json_encode($value));

        return $value;
    }

    public function putMany(array $kvPairs, Tenant $tenant = null): array
    {
        $tenant = $tenant ?? $this->tenant();

        foreach ($kvPairs as $key => $value) {
            $kvPairs[$key] = json_encode($value);
        }

        $this->redis->hmset("tenants:{$tenant->id}", $kvPairs);

        return $kvPairs;
    }
}
