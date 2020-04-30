<?php

namespace Stancl\Tenancy\Contracts;

use Stancl\Tenancy\DatabaseConfig;

interface ManagesDatabaseUsers
{
    public function createUser(DatabaseConfig $databaseConfig): void;
    public function deleteUser(DatabaseConfig $databaseConfig): void;
}
