<?php

declare(strict_types=1);

namespace Stancl\Tenancy\TenantDatabaseManagers;

use Stancl\Tenancy\Contracts\ModifiesDatabaseNameForConnection;
use Stancl\Tenancy\Contracts\TenantDatabaseManager;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

class SQLiteDatabaseManager implements TenantDatabaseManager, ModifiesDatabaseNameForConnection
{
    public function getSeparator(): string
    {
        return 'database';
    }

    public function createDatabase(TenantWithDatabase $tenant): bool
    {
        try {
            return fclose(fopen(database_path($tenant->database()->getName()), 'w'));
        } catch (\Throwable $th) {
            return false;
        }
    }

    public function deleteDatabase(TenantWithDatabase $tenant): bool
    {
        try {
            return unlink(database_path($tenant->database()->getName()));
        } catch (\Throwable $th) {
            return false;
        }
    }

    public function databaseExists(string $name): bool
    {
        return file_exists(database_path($name));
    }

    public function getDatabaseNameForConnection(string $original): string
    {
        return database_path($original);
    }
}
