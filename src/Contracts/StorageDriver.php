<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts;

use Stancl\Tenancy\Tenant;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedException;

interface StorageDriver
{
    public function createTenant(Tenant $tenant): void;

    public function updateTenant(Tenant $tenant): void;

    public function deleteTenant(Tenant $tenant): void;

    /**
     * Find a tenant using an id.
     *
     * @param string $id
     * @return Tenant
     * @throws TenantCouldNotBeIdentifiedException
     */
    public function findById(string $id): Tenant;

    /**
     * Find a tenant using a domain name.
     *
     * @param string $domain
     * @return Tenant
     */
    public function findByDomain(string $domain): Tenant;

    /**
     * Check if a tenant can be created.
     *
     * @param Tenant $tenant
     * @return true|TenantCannotBeCreatedException
     */
    public function canCreate(Tenant $tenant);
}
