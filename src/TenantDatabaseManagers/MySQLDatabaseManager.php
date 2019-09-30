<?php

declare(strict_types=1);

namespace Stancl\Tenancy\TenantDatabaseManagers;

use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseManager as IlluminateDatabaseManager;
use Stancl\Tenancy\Contracts\TenantDatabaseManager;

class MySQLDatabaseManager implements TenantDatabaseManager
{
    /** @var \Illuminate\Database\Connection */
    protected $database;

    public function __construct(Repository $config, IlluminateDatabaseManager $databaseManager)
    {
        $this->database = $databaseManager->connection($config['tenancy.database_manager_connections.mysql']);
    }

    public function createDatabase(string $name): bool
    {
        return $this->database->statement("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    public function deleteDatabase(string $name): bool
    {
        return $this->database->statement("DROP DATABASE `$name`");
    }

    public function databaseExists(string $name): bool
    {
        return (bool) $this->database->select("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$name'");
    }
}
