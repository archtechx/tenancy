<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\TenantDatabaseManagers;

use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;

class PostgreSQLDatabaseManager extends TenantDatabaseManager
{
    public function createDatabase(TenantWithDatabase $tenant): bool
    {
        return $this->connection()->statement("CREATE DATABASE \"{$tenant->database()->getName()}\" WITH TEMPLATE=template0");
    }

    public function deleteDatabase(TenantWithDatabase $tenant): bool
    {
        return $this->connection()->statement("DROP DATABASE \"{$tenant->database()->getName()}\"");
    }

    public function databaseExists(string $name): bool
    {
        return (bool) $this->connection()->selectOne("SELECT datname FROM pg_database WHERE datname = '$name'");
    }
}
