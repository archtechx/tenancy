<?php

declare(strict_types=1);

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
