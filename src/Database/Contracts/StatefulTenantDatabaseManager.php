<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Contracts;

use Illuminate\Database\Connection;
use Stancl\Tenancy\Database\Exceptions\NoConnectionSetException;

/**
 * Tenant database manager with a persistent connection.
 */
interface StatefulTenantDatabaseManager extends TenantDatabaseManager
{
    /** Get the DB connection used by the tenant database manager. */
    public function connection(): Connection;

    /**
     * Set the DB connection that should be used by the tenant database manager.
     *
     * @throws NoConnectionSetException
     */
    public function setConnection(string $connection): void;
}
