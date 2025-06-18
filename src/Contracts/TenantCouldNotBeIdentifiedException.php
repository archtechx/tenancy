<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts;

use Exception;

abstract class TenantCouldNotBeIdentifiedException extends Exception
{
    protected function tenantCouldNotBeIdentified(string $how): void
    {
        $this->message = 'Tenant could not be identified ' . $how;
    }
}
