<?php

declare(strict_types=1);

namespace Stancl\Tenancy\TenantDatabaseManagers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager as IlluminateDatabaseManager;
use Stancl\Tenancy\Contracts\TenantDatabaseManager;

class PostgreSQLDatabaseManager implements TenantDatabaseManager
{
    /** @var \Illuminate\Database\Connection */
    protected $database;

    public function __construct(Repository $config, IlluminateDatabaseManager $databaseManager)
    {
        $this->database = $databaseManager->connection($config['tenancy.database_manager_connections.pgsql']);
    }

    public function createDatabase(string $name): bool
    {
        return $this->database->statement("CREATE DATABASE \"$name\" WITH TEMPLATE=template0");
    }

    public function deleteDatabase(string $name): bool
    {
        return $this->database->statement("DROP DATABASE \"$name\"");
    }

    public function databaseExists(string $name): bool
    {
        return (bool) $this->database->select("SELECT datname FROM pg_database WHERE datname = '$name'");
    }
}
