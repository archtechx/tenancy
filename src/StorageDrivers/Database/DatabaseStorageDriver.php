<?php

declare(strict_types=1);

namespace Stancl\Tenancy\StorageDrivers\Database;

use Stancl\Tenancy\Contracts\StorageDriver;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedException;
use Stancl\Tenancy\StorageDrivers\Database\DomainModel as Domains;
use Stancl\Tenancy\StorageDrivers\Database\Tenants as Tenants;
use Stancl\Tenancy\Tenant;

class DatabaseStorageDriver implements StorageDriver
{
    // todo write tests verifying that data is decoded and added to the array

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
        return Tenant::fromStorage(Tenants::find($id)->decoded())
            ->withDomains(Domains::where('tenant_id', $id)->all()->only('domain')->toArray());
    }

    public function getTenantIdByDomain(string $domain): ?string
    {
        return Domains::where('domain', $domain)->first()->tenant_id ?? null;
    }

    public function createTenant(string $domain, string $id): void
    {
        DB::transaction(function () use ($domain, $id) {
            Tenants::create(['id' => $id, 'data' => '{}'])->toArray();
            Domains::create(['domain' => $domain, 'tenant_id' => $id]);
        });
    }

    public function updateTenant(Tenant $tenant): void
    {
        // todo
    }

    public function deleteTenant(string $id): bool
    {
        return Tenants::find($id)->delete();
    }

    public function all(array $ids = []): array
    {
        return Tenants::getAllTenants($ids)->toArray();
    }

    public function get(string $id, string $key)
    {
        return Tenants::find($id)->get($key);
    }

    public function getMany(string $id, array $keys): array
    {
        return Tenants::find($id)->getMany($keys);
    }

    public function put(string $id, string $key, $value)
    {
        return Tenants::find($id)->put($key, $value);
    }

    public function putMany(string $id, array $values): array
    {
        foreach ($values as $key => $value) { // todo performance
            Tenants::find($id)->put($key, $value);
        }

        return $values;
    }
}
