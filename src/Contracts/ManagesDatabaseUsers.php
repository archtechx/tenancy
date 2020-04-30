<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts;

use Stancl\Tenancy\DatabaseConfig;

interface ManagesDatabaseUsers
{
    public function createUser(DatabaseConfig $databaseConfig): void;

    public function deleteUser(DatabaseConfig $databaseConfig): void;
}
