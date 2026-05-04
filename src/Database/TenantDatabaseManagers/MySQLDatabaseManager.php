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

        $this->validateParameter([$database, $charset, $collation]);

        // MySQL defaults to the server's charset and collation
        // if charset and collation are not specified.
        // If charset is specified but collation is null, MySQL
        // will choose a default collation for the specified charset (and vice versa).
        $statement = "CREATE DATABASE `{$database}`";

        if ($charset !== null) {
            $statement .= " CHARACTER SET `{$charset}`";
        }

        if ($collation !== null) {
            $statement .= " COLLATE `{$collation}`";
        }

        return $this->connection()->statement($statement);
    }

    public function deleteDatabase(TenantWithDatabase $tenant): bool
    {
        $database = $tenant->database()->getName();

        $this->validateParameter($database);

        return $this->connection()->statement("DROP DATABASE `{$database}`");
    }

    public function databaseExists(string $name): bool
    {
        return (bool) $this->connection()->select('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?', [$name]);
    }
}
