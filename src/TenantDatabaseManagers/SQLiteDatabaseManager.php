<?php

declare(strict_types=1);

namespace Stancl\Tenancy\TenantDatabaseManagers;

use Stancl\Tenancy\Contracts\TenantDatabaseManager;
use Stancl\Tenancy\Tenant;

class SQLiteDatabaseManager implements TenantDatabaseManager
{
    public function createDatabase(string $name, Tenant $tenant): bool
    {
        try {
            return fclose(fopen(database_path($name), 'w'));
        } catch (\Throwable $th) {
            return false;
        }
    }

    public function deleteDatabase(string $name): bool
    {
        try {
            return unlink(database_path($name));
        } catch (\Throwable $th) {
            return false;
        }
    }

    public function databaseExists(string $name): bool
    {
        return file_exists(database_path($name));
    }

    public function createDatabaseConnection(Tenant $tenant, array $baseConfiguration): array
    {
        return array_replace_recursive($baseConfiguration, [
            'database' => database_path($tenant->getDatabaseName()),
        ]);
    }
}
