<?php

namespace Stancl\Tenancy\Contracts\Future;

use Illuminate\Database\Connection;

/**
 * This interface *might* be part of the TenantDatabaseManager interface in 3.x.
 */
interface CanSetConnection
{
    public function setConnection(Connection $connection);
}