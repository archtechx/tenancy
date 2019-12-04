<?php

declare(strict_types=1);

namespace Stancl\Tenancy\TenantDatabaseManagers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager as IlluminateDatabaseManager;
use Stancl\Tenancy\Contracts\TenantDatabaseManager;

class PostgreSQLSchemaManager implements TenantDatabaseManager
{
    /** @var \Illuminate\Database\Connection */
    protected $database;

    public function __construct(Repository $config, IlluminateDatabaseManager $databaseManager)
    {
        $this->database = $databaseManager->connection($config['tenancy.database_manager_connections.pgsql']);
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
}
