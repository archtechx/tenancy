<?php

declare(strict_types=1);

namespace Stancl\Tenancy\TenantDatabaseManagers;

use Stancl\Tenancy\Interfaces\TenantDatabaseManager;

class SQLiteDatabaseManager implements TenantDatabaseManager
{
    public function createDatabase(string $name): bool
    {
        try {
            return \fclose(\fopen(database_path($name), 'w'));
        } catch (\Throwable $th) {
            return false;
        }
    }

    public function deleteDatabase(string $name): bool
    {
        try {
            return \unlink(database_path($name));
        } catch (\Throwable $th) {
            return false;
        }
    }
}
