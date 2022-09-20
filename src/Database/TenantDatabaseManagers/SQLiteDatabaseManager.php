<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\TenantDatabaseManagers;

use Stancl\Tenancy\Database\Contracts\TenantDatabaseManager;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;
use Throwable;

class SQLiteDatabaseManager implements TenantDatabaseManager
{
    /**
     * SQLite Database path without ending slash.
     */
    public static string|null $path = null;

    public function createDatabase(TenantWithDatabase $tenant): bool
    {
        try {
            return (bool) file_put_contents($this->getPath($tenant->database()->getName()), '');
        } catch (Throwable) {
            return false;
        }
    }

    public function deleteDatabase(TenantWithDatabase $tenant): bool
    {
        try {
            return unlink($this->getPath($tenant->database()->getName()));
        } catch (Throwable) {
            return false;
        }
    }

    public function databaseExists(string $name): bool
    {
        return file_exists($this->getPath($name));
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

    public function getPath(string $name): string
    {
        if (static::$path) {
            // The path is set, so return the full path with the database name
            return str(static::$path)->append(DIRECTORY_SEPARATOR)->append($name)->toString();
        }

        // The path is not set so return the default path
        return database_path($name);
    }
}
