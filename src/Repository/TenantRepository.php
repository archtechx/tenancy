<?php

namespace Stancl\Tenancy\Repository;

use Stancl\Tenancy\Contracts\Tenant;

interface TenantRepository
{
    public function find(string|int $id): ?Tenant;

    public function findForDomain(string $domain): ?Tenant;

    /**
     * @return iterable<Tenant>
     */
    public function all(): iterable;

    /**
     * @return iterable<Tenant>
     */
    public function whereKeyIn(string|int ...$ids): iterable;
}
