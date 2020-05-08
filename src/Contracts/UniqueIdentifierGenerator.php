<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts;

use Stancl\Tenancy\Database\Models\Tenant;

interface UniqueIdentifierGenerator
{
    /**
     * Generate a unique identifier.
     */
    public static function generate(Tenant $tenant): string;
}
