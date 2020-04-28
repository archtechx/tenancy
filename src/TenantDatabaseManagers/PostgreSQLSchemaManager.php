<?php

declare(strict_types=1);

namespace Stancl\Tenancy\TenantDatabaseManagers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Contracts\Future\CanSetConnection;
use Stancl\Tenancy\Contracts\TenantDatabaseManager;
use Stancl\Tenancy\Tenant;

class PostgreSQLSchemaManager implements TenantDatabaseManager, CanSetConnection
{
    /** @var string */
    protected $connection;

    public function __construct(Repository $config)
    {
        $this->connection = $config->get('tenancy.database_manager_connections.pgsql');
    }

    protected function database(): Connection
    {
        return DB::connection($this->connection);
    }

    public function setConnection(string $connection): void
    {
        $this->connection = $connection;
    }

    public function createDatabase(string $name, Tenant $tenant): bool
    {
        return $this->database()->statement("CREATE SCHEMA \"$name\"");
    }

    public function deleteDatabase(string $name): bool
    {
        return $this->database()->statement("DROP SCHEMA \"$name\"");
    }

    public function databaseExists(string $name): bool
    {
        return (bool) $this->database()->select("SELECT schema_name FROM information_schema.schemata WHERE schema_name = '$name'");
    }

    public function createDatabaseConnection(Tenant $tenant, array $baseConfiguration): array
    {
        if ('pgsql' !== $baseConfiguration['driver']) {
            throw new \Exception('Mismatching driver for tenant');
        }

        return array_replace_recursive($baseConfiguration, [
            'schema' => $tenant->getDatabaseName()
        ]);
    }
}
