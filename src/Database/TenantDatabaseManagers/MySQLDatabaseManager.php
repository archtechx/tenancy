<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\TenantDatabaseManagers;

use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;

class MySQLDatabaseManager extends TenantDatabaseManager
{
    public function createDatabase(TenantWithDatabase $tenant): bool
    {
        $database = $tenant->database()->getName();
        $charset = $this->connection()->getConfig('charset');
        $collation = $this->connection()->getConfig('collation');

        return $this->connection()->statement("CREATE DATABASE `{$database}` CHARACTER SET `$charset` COLLATE `$collation`");
    }

    public function deleteDatabase(TenantWithDatabase $tenant): bool
    {
        return $this->connection()->statement("DROP DATABASE `{$tenant->database()->getName()}`");
    }

    public function databaseExists(string $name): bool
    {
        return (bool) $this->connection()->select("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$name'");
    }
}
