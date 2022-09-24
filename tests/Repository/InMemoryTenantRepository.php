<?php

namespace Stancl\Tenancy\Tests\Repository;

use Illuminate\Support\Collection;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Repository\TenantRepository;

class InMemoryTenantRepository implements TenantRepository
{
    public function __construct(
        /** @var Collection<Tenant> */
        private Collection $tenants = new Collection(),
    ) {
    }

    public function find(int|string $id): ?Tenant
    {
        return $this->tenants->first(fn (Tenant $tenant) => $tenant->getTenantKey() == $id);
    }

    public function findForDomain(string $domain): ?Tenant
    {
        return $this->tenants->firstWhere(fn (Tenant $tenant) => in_array($domain, $tenant->domains ?? []));
    }

    public function all(): iterable
    {
        return $this->tenants->lazy();
    }

    public function whereKeyIn(string|int ...$ids): iterable
    {
        return $this->tenants->filter(fn (Tenant $tenant) => in_array($tenant->getTenantKey(), $ids))->lazy();
    }

    public function store(Tenant $tenant): void
    {
        $this->tenants->push($tenant);
    }
}
