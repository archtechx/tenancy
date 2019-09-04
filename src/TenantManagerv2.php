<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

final class TenantManagerv2
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
