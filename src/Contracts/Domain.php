<?php

namespace Stancl\Tenancy\Contracts;

/**
 * @property-read Tenant $tenant
 * 
 * @see \Stancl\Tenancy\Database\Models\Domain
 */
interface Domain
{
    public function tenant();
}
