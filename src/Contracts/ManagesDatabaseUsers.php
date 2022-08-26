<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts;

use Stancl\Tenancy\DatabaseConfig;

// todo possibly move to Database namespace, along with other classes

interface ManagesDatabaseUsers extends TenantDatabaseManager
{
    /** Create a database user. */
    public function createUser(DatabaseConfig $databaseConfig): bool;

    /** Delete a database user. */
    public function deleteUser(DatabaseConfig $databaseConfig): bool;

    /** Does a database user exist? */
    public function userExists(string $username): bool;
}
