<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use Stancl\Tenancy\Database\DatabaseConfig;
use Stancl\Tenancy\Database\TenantDatabaseManagers\TenantDatabaseManager;

/**
 * @method \Illuminate\Database\Connection database()
 * @mixin TenantDatabaseManager
 * @phpstan-require-extends TenantDatabaseManager
 */
trait ManagesPostgresUsers
{
    /**
     * Grant database/schema and table permissions to the user whose credentials are stored in the passed DatabaseConfig.
     *
     * With schema manager, the schema name is stored in the 'search_path' key of the connection config,
     * but it's still accessible using $databaseConfig->getName().
     */
    abstract protected function grantPermissions(DatabaseConfig $databaseConfig): bool;

    public function createUser(DatabaseConfig $databaseConfig): bool
    {
        /** @var string $username */
        $username = $databaseConfig->getUsername();
        $password = $databaseConfig->getPassword();

        $createUser = ! $this->userExists($username);

        if ($createUser) {
            $this->connection()->statement("CREATE USER \"{$username}\" LOGIN PASSWORD '{$password}'");
        }

        $this->grantPermissions($databaseConfig);

        return $createUser;
    }

    public function deleteUser(DatabaseConfig $databaseConfig): bool
    {
        // Tenant DB username
        $username = $databaseConfig->getUsername();

        // Tenant host connection config
        $connectionName = $this->connection()->getConfig('name');
        $centralDatabase = $this->connection()->getConfig('database');

        // Set the DB/schema name to the tenant DB/schema name
        config()->set(
            "database.connections.{$connectionName}",
            $this->makeConnectionConfig($this->connection()->getConfig(), $databaseConfig->getName()),
        );

        // Connect to the tenant DB/schema
        $this->connection()->reconnect();

        // Delete all database objects owned by the user (privileges, tables, views, etc.)
        // Postgres users cannot be deleted unless we delete all objects owned by it first
        $this->connection()->statement("DROP OWNED BY \"{$username}\"");

        // Delete the user
        $userDeleted = $this->connection()->statement("DROP USER \"{$username}\"");

        config()->set(
            "database.connections.{$connectionName}",
            $this->makeConnectionConfig($this->connection()->getConfig(), $centralDatabase),
        );

        // Reconnect to the central database
        $this->connection()->reconnect();

        return $userDeleted;
    }

    public function userExists(string $username): bool
    {
        return (bool) $this->connection()->selectOne("SELECT usename FROM pg_user WHERE usename = '{$username}'");
    }
}
