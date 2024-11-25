<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\TenantDatabaseManagers;

use Stancl\Tenancy\Database\Concerns\CreatesDatabaseUsers;
use Stancl\Tenancy\Database\Concerns\ManagesPostgresUsers;
use Stancl\Tenancy\Database\Contracts\ManagesDatabaseUsers;
use Stancl\Tenancy\Database\DatabaseConfig;

class PermissionControlledPostgreSQLDatabaseManager extends PostgreSQLDatabaseManager implements ManagesDatabaseUsers
{
    use CreatesDatabaseUsers, ManagesPostgresUsers;

    protected function grantPermissions(DatabaseConfig $databaseConfig): bool
    {
        // Tenant DB config
        $database = $databaseConfig->getName();
        $username = $databaseConfig->getUsername();
        $schema = $databaseConfig->connection()['search_path'];

        // Host config
        $connectionName = $this->connection()->getConfig('name');
        $centralDatabase = $this->connection()->getConfig('database');

        $this->connection()->statement("GRANT CONNECT ON DATABASE \"{$database}\" TO \"{$username}\"");

        // Connect to tenant database
        config(["database.connections.{$connectionName}.database" => $database]);

        $this->connection()->reconnect();

        // Grant permissions to create and use tables in the configured schema ("public" by default) to the user
        $this->connection()->statement("GRANT USAGE, CREATE ON SCHEMA {$schema} TO \"{$username}\"");

        // Grant permissions to use sequences in the current schema to the user
        $this->connection()->statement("GRANT USAGE ON ALL SEQUENCES IN SCHEMA {$schema} TO \"{$username}\"");

        // Reconnect to central database
        config(["database.connections.{$connectionName}.database" => $centralDatabase]);

        $this->connection()->reconnect();

        return true;
    }
}
