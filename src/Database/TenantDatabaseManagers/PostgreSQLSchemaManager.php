<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\TenantDatabaseManagers;

use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;

class PostgreSQLSchemaManager extends TenantDatabaseManager
{
    public function createDatabase(TenantWithDatabase $tenant): bool
    {
        return $this->database()->statement("CREATE SCHEMA \"{$tenant->database()->getName()}\"");
    }

    public function deleteDatabase(TenantWithDatabase $tenant): bool
    {
        return $this->database()->statement("DROP SCHEMA \"{$tenant->database()->getName()}\" CASCADE");
    }

    public function databaseExists(string $name): bool
    {
        return (bool) $this->database()->select("SELECT schema_name FROM information_schema.schemata WHERE schema_name = '$name'");
    }

    public function makeConnectionConfig(array $baseConfig, string $databaseName): array
    {
        if (version_compare(app()->version(), '9.0', '>=')) {
            $baseConfig['search_path'] = $databaseName;
        } else {
            $baseConfig['schema'] = $databaseName;
        }

        return $baseConfig;
    }
}
