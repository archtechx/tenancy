<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\TenantDatabaseManagers;

use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;

class PostgreSQLSchemaManager extends TenantDatabaseManager
{
    public function createDatabase(TenantWithDatabase $tenant): bool
    {
        return $this->connection()->statement("CREATE SCHEMA \"{$tenant->database()->getName()}\"");
    }

    public function deleteDatabase(TenantWithDatabase $tenant): bool
    {
        return $this->connection()->statement("DROP SCHEMA \"{$tenant->database()->getName()}\" CASCADE");
    }

    public function databaseExists(string $name): bool
    {
        return (bool) $this->connection()->select("SELECT schema_name FROM information_schema.schemata WHERE schema_name = '$name'");
    }

    public function makeConnectionConfig(array $baseConfig, string $databaseName): array
    {
        $baseConfig['search_path'] = $databaseName;

        return $baseConfig;
    }
}
