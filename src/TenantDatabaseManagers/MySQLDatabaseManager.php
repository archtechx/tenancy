<?php

declare(strict_types=1);

namespace Stancl\Tenancy\TenantDatabaseManagers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager as IlluminateDatabaseManager;
use Stancl\Tenancy\Contracts\Future\CanSetConnection;
use Stancl\Tenancy\Contracts\TenantDatabaseManager;

class MySQLDatabaseManager implements TenantDatabaseManager, CanSetConnection
{
    /** @var Connection */
    protected $database;

    public function __construct(Repository $config, IlluminateDatabaseManager $databaseManager)
    {
        $this->database = $databaseManager->connection($config['tenancy.database_manager_connections.mysql']);
    }

    public function setConnection(Connection $connection)
    {
        $this->database = $connection;
    }

    public function createDatabase(string $name): bool
    {
        $charset = $this->database->getConfig('charset');
        $collation = $this->database->getConfig('collation');

        return $this->database->statement("CREATE DATABASE `$name` CHARACTER SET `$charset` COLLATE `$collation`");
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
