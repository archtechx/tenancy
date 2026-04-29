<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\TenantDatabaseManagers;

use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;

class PostgreSQLDatabaseManager extends TenantDatabaseManager
{
    public function createDatabase(TenantWithDatabase $tenant): bool
    {
        $name = $this->validateParameter($tenant->database()->getName());

        return $this->connection()->statement("CREATE DATABASE \"{$name}\" WITH TEMPLATE=template0");
    }

    public function deleteDatabase(TenantWithDatabase $tenant): bool
    {
        $name = $this->validateParameter($tenant->database()->getName());

        return $this->connection()->statement("DROP DATABASE \"{$name}\"");
    }

    public function databaseExists(string $name): bool
    {
        return (bool) $this->connection()->select("SELECT datname FROM pg_database WHERE datname = ?", [$name]);
    }
}
