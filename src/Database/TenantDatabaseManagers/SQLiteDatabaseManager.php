<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\TenantDatabaseManagers;

use Stancl\Tenancy\Database\Contracts\TenantDatabaseManager;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;
use Throwable;

class SQLiteDatabaseManager implements TenantDatabaseManager
{
    public function createDatabase(TenantWithDatabase $tenant): bool
    {
        try {
            return file_put_contents(database_path($tenant->database()->getName()), '');
        } catch (Throwable) {
            return false;
        }
    }

    public function deleteDatabase(TenantWithDatabase $tenant): bool
    {
        try {
            return unlink(database_path($tenant->database()->getName()));
        } catch (Throwable) {
            return false;
        }
    }

    public function databaseExists(string $name): bool
    {
        return file_exists(database_path($name));
    }

    public function makeConnectionConfig(array $baseConfig, string $databaseName): array
    {
        $baseConfig['database'] = database_path($databaseName);

        return $baseConfig;
    }

    public function setConnection(string $connection): void
    {
        //
    }
}
