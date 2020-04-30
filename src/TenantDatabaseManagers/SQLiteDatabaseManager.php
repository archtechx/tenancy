<?php

declare(strict_types=1);

namespace Stancl\Tenancy\TenantDatabaseManagers;

use Stancl\Tenancy\Contracts\ModifiesDatabaseNameForConnection;
use Stancl\Tenancy\Contracts\TenantDatabaseManager;

class SQLiteDatabaseManager implements TenantDatabaseManager, ModifiesDatabaseNameForConnection
{
    public function getSeparator(): string
    {
        return 'database';
    }

    public function createDatabase(string $name): bool
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

    public function getDatabaseNameForConnection(string $original): string
    {
        return database_path($original);
    }
}
