<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\TenantDatabaseManagers;

use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;

class PostgreSQLDatabaseManager extends TenantDatabaseManager
{
    public function createDatabase(TenantWithDatabase $tenant): bool
    {
        $database = $tenant->database()->getName();
        $charset = $this->connection()->getConfig('charset');
        $collation = $this->connection()->getConfig('collation');

        return $this->connection()->statement("CREATE DATABASE \"{$database}\" WITH TEMPLATE=template0 ENCODING='{$charset}' LC_COLLATE='{$collation}' LC_CTYPE='{$collation}'");
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
