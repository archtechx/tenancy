<?php

declare(strict_types=1);

namespace Stancl\Tenancy\StorageDrivers\Database;

use Stancl\Tenancy\Contracts\StorageDriver;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedException;
use Stancl\Tenancy\Tenant;
use Stancl\Tenancy\StorageDrivers\Database\Tenants as Tenants;
use Stancl\Tenancy\StorageDrivers\Database\DomainModel as Domains;

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

    public function findById(string $id): Tenant
    {
        return Tenant::fromStorage(Tenants::find($id)->decoded())
            ->withDomains(Domains::where('tenant_id', $id)->all()->only('domain')->toArray());
    }

    public function canCreateTenant(Tenant $tenant)
    {
        // todo
    }

    public function getTenantIdByDomain(string $domain): ?string
    {
        return Domains::where('domain', $domain)->first()->tenant_id ?? null;
    }

    public function createTenant(Tenant $tenant): void
    {
        DB::transaction(function () use ($tenant) {
            Tenants::create(['id' => $tenant->id, 'data' => '{}'])->toArray();

            $domainData = [];
            foreach($tenant->domains as $domain) {
                $domainData[] = ['domain' => $domain, 'tenant_id' => $tenant->id];
            }
            Domains::create($domainData);
        });
    }
    
    public function updateTenant(Tenant $tenant): void
    {
        // todo
    }

    public function deleteTenant(Tenant $tenant): void
    {
        Tenants::find($tenant->id)->delete();
        // todo domains
    }

    public function all(array $ids = []): array
    {
        return Tenants::getAllTenants($ids)->toArray();
    }

    public function get(string $key, Tenant $tenant = null)
    {
        return Tenants::find($tenant->id)->get($key);
    }

    // todo storage methods default to current tenant
    public function getMany(array $keys, Tenant $tenant = null): array
    {
        return Tenants::find($tenant->id)->getMany($keys);
    }

    public function put(string $key, $value, Tenant $tenant = null): void
    {
        Tenants::find($tenant->id)->put($key, $value);
    }

    public function putMany(array $kvPairs, Tenant $tenant = null): void
    {
        foreach ($kvPairs as $key => $value) { // todo performance
            Tenants::find($tenant->id)->put($key, $value);
        }
    }
}
