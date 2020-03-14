<?php

declare(strict_types=1);

namespace Stancl\Tenancy\StorageDrivers\Database;

use Illuminate\Config\Repository as ConfigRepository;
use Stancl\Tenancy\Tenant;

class DomainRepository extends Repository
{
    public function getTenantIdByDomain(string $domain): ?string
    {
        return $this->where('domain', $domain)->first()->tenant_id ?? null;
    }

    public function occupied(array $domains): bool
    {
        return $this->whereIn('domain', $domains)->exists();
    }

    public function getTenantDomains($tenant)
    {
        $id = $tenant instanceof Tenant ? $tenant->id : $tenant;

        return $this->where('tenant_id', $id)->get('domain')->pluck('domain')->all();
    }

    public function insertTenantDomains(Tenant $tenant)
    {
        $this->insert(array_map(function ($domain) use ($tenant) {
            return ['domain' => $domain, 'tenant_id' => $tenant->id];
        }, $tenant->domains));
    }

    public function updateTenantDomains(Tenant $tenant, array $originalDomains)
    {
        $deletedDomains = array_diff($originalDomains, $tenant->domains);
        $newDomains = array_diff($tenant->domains, $originalDomains);

        $this->whereIn('domain', $deletedDomains)->delete();

        foreach ($newDomains as $domain) {
            $this->insert([
                'tenant_id' => $tenant->id,
                'domain' => $domain,
            ]);
        }
    }

    public function getTable(ConfigRepository $config)
    {
        return $config->get('tenancy.storage_drivers.db.table_names.DomainModel') // legacy
            ?? $config->get('tenancy.storage_drivers.db.table_names.domains')
            ?? 'domains';
    }
}
