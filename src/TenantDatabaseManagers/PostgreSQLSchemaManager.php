<?php

declare(strict_types=1);

namespace Stancl\Tenancy\TenantDatabaseManagers;

use Illuminate\Database\Connection;
use Illuminate\Contracts\Config\Repository;
use Stancl\Tenancy\Contracts\Future\CanSetConnection;
use Illuminate\Database\DatabaseManager;
use Stancl\Tenancy\Contracts\TenantDatabaseManager;

class PostgreSQLSchemaManager implements TenantDatabaseManager, CanSetConnection
{
    /** @var Connection */
    protected $database;

    /** @var string */
    protected $connection;

    public function __construct(Repository $config, DatabaseManager $databaseManager)
    {
        $this->connection = $config['tenancy.database_manager_connections.pgsql'];

        $this->database = $databaseManager->connection($this->connection);
    }

    public function createDatabase(string $name): bool
    {
        return $this->database->statement("CREATE SCHEMA \"$name\"");
    }

    public function deleteDatabase(string $name): bool
    {
        return $this->database->statement("DROP SCHEMA \"$name\"");
    }

    public function databaseExists(string $name): bool
    {
        return (bool) $this->database->select("SELECT schema_name FROM information_schema.schemata WHERE schema_name = '$name'");
    }

    public function setConnection(string $connection): void
    {
        $this->connection = $connection;
    }
}
