<?php

declare(strict_types=1);

// todo likely move all of these classes to Database\

namespace Stancl\Tenancy\Database\TenantDatabaseManagers;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Database\Contracts\TenantDatabaseManager;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Exceptions\NoConnectionSetException;

class MicrosoftSQLDatabaseManager implements TenantDatabaseManager
{
    protected string $connection; // todo docblock, in all of these classes

    protected function database(): Connection // todo consider abstracting this method & setConnection() into a base class
    {
        if ($this->connection === null) {
            throw new NoConnectionSetException(static::class);
        }

        return DB::connection($this->connection);
    }

    public function setConnection(string $connection): void
    {
        $this->connection = $connection;
    }

    public function createDatabase(TenantWithDatabase $tenant): bool
    {
        $database = $tenant->database()->getName();
        $charset = $this->database()->getConfig('charset');
        $collation = $this->database()->getConfig('collation'); // todo check why these are not used

        return $this->database()->statement("CREATE DATABASE [{$database}]");
    }

    public function deleteDatabase(TenantWithDatabase $tenant): bool
    {
        return $this->database()->statement("DROP DATABASE [{$tenant->database()->getName()}]");
    }

    public function databaseExists(string $name): bool
    {
        return (bool) $this->database()->select("SELECT name FROM master.sys.databases WHERE name = '$name'");
    }

    public function makeConnectionConfig(array $baseConfig, string $databaseName): array
    {
        $baseConfig['database'] = $databaseName;

        return $baseConfig;
    }
}
