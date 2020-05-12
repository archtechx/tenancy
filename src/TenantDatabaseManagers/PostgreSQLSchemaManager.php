<?php

declare(strict_types=1);

namespace Stancl\Tenancy\TenantDatabaseManagers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Contracts\Future\CanSetConnection;
use Stancl\Tenancy\Contracts\TenantDatabaseManager;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

class PostgreSQLSchemaManager implements TenantDatabaseManager, CanSetConnection
{
    /** @var string */
    protected $connection;

    public function __construct(Repository $config)
    {
        $this->connection = $config->get('tenancy.database_manager_connections.pgsql');
    }

    public function getSeparator(): string
    {
        return 'schema';
    }

    protected function database(): Connection
    {
        return DB::connection($this->connection);
    }

    public function setConnection(string $connection): void
    {
        $this->connection = $connection;
    }

    public function createDatabase(TenantWithDatabase $tenant): bool
    {
        return $this->database()->statement("CREATE SCHEMA \"{$tenant->database()->getName()}\"");
    }

    public function deleteDatabase(TenantWithDatabase $tenant): bool
    {
        return $this->database()->statement("DROP SCHEMA \"{$tenant->database()->getName()}\"");
    }

    public function databaseExists(string $name): bool
    {
        return (bool) $this->database()->select("SELECT schema_name FROM information_schema.schemata WHERE schema_name = '$name'");
    }

    public function makeConnectionConfig(array $baseConfig, string $databaseName): array
    {
        $baseConfig['schema'] = $databaseName;

        return $baseConfig;
    }
}
