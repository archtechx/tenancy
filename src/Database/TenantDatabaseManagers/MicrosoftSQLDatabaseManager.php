<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\TenantDatabaseManagers;

use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;

class MicrosoftSQLDatabaseManager extends TenantDatabaseManager
{
    public function createDatabase(TenantWithDatabase $tenant): bool
    {
        $database = $tenant->database()->getName();

        return $this->connection()->statement("CREATE DATABASE [{$database}]");
    }

    public function deleteDatabase(TenantWithDatabase $tenant): bool
    {
        return $this->connection()->statement("DROP DATABASE [{$tenant->database()->getName()}]");
    }

    public function databaseExists(string $name): bool
    {
        return (bool) $this->connection()->select("SELECT name FROM master.sys.databases WHERE name = '$name'");
    }
}
