<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\TenantDatabaseManagers;

use AssertionError;
use PDO;
use Stancl\Tenancy\Database\Contracts\TenantDatabaseManager;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;
use Throwable;

class SQLiteDatabaseManager implements TenantDatabaseManager
{
    /**
     * SQLite Database path without ending slash.
     */
    public static string|null $path = null;

    /**
     * Should the WAL journal mode be used for newly created databases.
     *
     * @see https://www.sqlite.org/pragma.html#pragma_journal_mode
     */
    public static bool $WAL = true;

    public function createDatabase(TenantWithDatabase $tenant): bool
    {
        try {
            if (file_put_contents($path = $this->getPath($tenant->database()->getName()), '') === false) {
                return false;
            }

            if (static::$WAL) {
                $pdo = new PDO('sqlite:' . $path);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                // @phpstan-ignore-next-line method.nonObject
                assert($pdo->query('pragma journal_mode = wal')->fetch(PDO::FETCH_ASSOC)['journal_mode'] === 'wal', 'Unable to set journal mode to wal.');
            }

            return true;
        } catch (AssertionError $e) {
            throw $e;
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
            return static::$path . DIRECTORY_SEPARATOR . $name;
        }

        return database_path($name);
    }
}
