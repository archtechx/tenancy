<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts\Future;

/**
 * This interface *might* be part of the TenantDatabaseManager interface in 3.x.
 */
interface CanSetConnection
{
    public function setConnection(string $connection): void;
}
