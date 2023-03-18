<?php

namespace Stancl\Tenancy\Facades;

use Stancl\Tenancy\Database\Models\Tenant;
use Stancl\Tenancy\Tenancy;

class TenancyFake extends Tenancy
{
    public function initialize($tenant): void
    {
        if (!is_object($tenant)) {
            $tenantId = $tenant;
            $tenant = $this->find($tenantId);
        }

        if ($this->initialized && $this->tenant->id === $tenant->id) {
            return;
        }

        // TODO: Remove this (so that runForMultiple() is still performant) and make the FS bootstrapper work either way
        if ($this->initialized) {
            $this->end();
        }

        $this->tenant = $tenant;

        $this->initialized = true;
    }

    public function end(): void
    {
        if (!$this->initialized) {
            return;
        }

        $this->initialized = false;

        $this->tenant = null;
    }

    public function find($id): ?Tenant
    {
        return new Tenant(['id' => $id]);
    }
}
