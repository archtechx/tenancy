<?php

namespace Stancl\Tenancy\Database\Concerns;

trait TenantConnection
{
    public function getConnectionName()
    {
        return 'tenant';
    }
}
