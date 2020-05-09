<?php

namespace Stancl\Tenancy\Database\Models\Concerns;

trait TenantConnection
{
    public function getConnectionName()
    {
        return 'tenant';
    }
}
