<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts;

use Stancl\Tenancy\Tenant;

interface StorageDriver
{
    public function createTenant(Tenant $tenant): bool; // todo return type

    public function updateTenant(Tenant $tenant): bool; // todo return type

    public function findById(string $id): Tenant;

    public function findByDomain(string $domain): Tenant;
}
