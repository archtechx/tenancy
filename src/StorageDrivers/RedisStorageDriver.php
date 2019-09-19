<?php

declare(strict_types=1);

namespace Stancl\Tenancy\StorageDrivers;

use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Foundation\Application;
use Stancl\Tenancy\Contracts\StorageDriver;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedException;
use Stancl\Tenancy\Tenant;

// todo2 transactions instead of pipelines?
class RedisStorageDriver implements StorageDriver
{
    // todo2 json encoding?

    /** @var Application */
    protected $app;

    /** @var Redis */
    protected $redis;

    /** @var Tenant The default tenant. */
    protected $tenant;

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
        return $this->tenant ?? $this->app[Tenant::class];
    }

    public function withDefaultTenant(Tenant $tenant): self
    {
        $this->tenant = $tenant;

        return $this;
    }

    public function ensureTenantCanBeCreated(Tenant $tenant): void
    {
        // todo2
    }

    public function findByDomain(string $domain): Tenant
    {
        $id = $this->getTenantIdByDomain($domain);
        if (! $id) {
            throw new TenantCouldNotBeIdentifiedException($domain);
        }

        return $this->findById($id);
    }

    public function findById(string $id): Tenant
    {
        return $this->makeTenant($this->redis->hgetall("tenants:$id"));
    }

    public function getTenantIdByDomain(string $domain): ?string
    {
        return $this->redis->hget("domains:$domain", 'tenant_id') ?: null;
    }

    public function createTenant(Tenant $tenant): void
    {
        $this->redis->pipeline(function ($pipe) use ($tenant) {
            foreach ($tenant->domains as $domain) {
                $pipe->hmset("domains:$domain", ['tenant_id' => $tenant->id]);
            }

            $data = [];
            foreach ($tenant->data as $key => $value) {
                $data[$key] = json_encode($value);
            }

            $pipe->hmset("tenants:{$tenant->id}", array_merge($data, ['_tenancy_domains' => json_encode($tenant->domains)]));
        });
    }

    public function updateTenant(Tenant $tenant): void
    {
        $this->redis->pipeline(function ($pipe) use ($tenant) {
            $pipe->hmset("tenants:{$tenant->id}", $tenant->data); // todo domains

            foreach ($tenant->domains as $domain) {
                $pipe->hmset("domains:$domain", 'tenant_id', $tenant->id);
            }

            // todo2 deleted domains
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

    /**
     * Return a list of all tenants.
     *
     * @param string[] $ids
     * @return Tenant[]
     */
    public function all(array $ids = []): array
    {
        // todo2 $this->redis->pipeline()
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
            return $this->makeTenant($this->redis->hgetall($tenant));
        }, $hashes);
    }

    /**
     * Make a Tenant instance from low-level array data.
     *
     * @param array $data
     * @return Tenant
     */
    protected function makeTenant(array $data): Tenant
    {
        foreach ($data as $key => $value) {
            $data[$key] = json_decode($value, true);
        }

        $domains = $data['_tenancy_domains'];
        unset($data['_tenancy_domains']);

        return Tenant::fromStorage($data)->withDomains($domains);
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
            $result[$key] = json_decode($values[$i], true);
        }

        return $result;
    }

    public function put(string $key, $value, Tenant $tenant = null): void
    {
        $tenant = $tenant ?? $this->tenant();
        $this->redis->hset("tenants:{$tenant->id}", $key, json_encode($value));
    }

    public function putMany(array $kvPairs, Tenant $tenant = null): void
    {
        $tenant = $tenant ?? $this->tenant();

        foreach ($kvPairs as $key => $value) {
            $kvPairs[$key] = json_encode($value);
        }

        $this->redis->hmset("tenants:{$tenant->id}", $kvPairs);
    }
}
