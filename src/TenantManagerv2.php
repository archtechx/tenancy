<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

final class TenantManager
{
    public function addTenant(Tenant $tenant): self
    {
        $this->storage->addTenant($tenant);

        return $this;
    }

    public function updateTenant(Tenant $tenant): self
    {
        $this->storage->updateTenant($tenant);

        return $this;
    }
}