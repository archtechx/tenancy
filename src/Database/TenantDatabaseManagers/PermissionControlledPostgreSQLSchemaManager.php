<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\TenantDatabaseManagers;

use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Database\Concerns\CreatesDatabaseUsers;
use Stancl\Tenancy\Database\Concerns\ManagesPostgresUsers;
use Stancl\Tenancy\Database\Contracts\ManagesDatabaseUsers;
use Stancl\Tenancy\Database\DatabaseConfig;

class PermissionControlledPostgreSQLSchemaManager extends PostgreSQLSchemaManager implements ManagesDatabaseUsers
{
    use CreatesDatabaseUsers, ManagesPostgresUsers;

    protected function grantPermissions(DatabaseConfig $databaseConfig): bool
    {
        // Tenant DB config
        $username = $databaseConfig->getUsername();
        $schema = $databaseConfig->getName();

        // Central database name
        $database = DB::connection(config('tenancy.database.central_connection'))->getDatabaseName();

        $this->connection()->statement("GRANT CONNECT ON DATABASE {$database} TO \"{$username}\"");
        $this->connection()->statement("GRANT USAGE, CREATE ON SCHEMA \"{$schema}\" TO \"{$username}\"");
        $this->connection()->statement("GRANT USAGE ON ALL SEQUENCES IN SCHEMA \"{$schema}\" TO \"{$username}\"");

        $tables = $this->connection()->select("SELECT table_name FROM information_schema.tables WHERE table_schema = '{$schema}' AND table_type = 'BASE TABLE'");

        // Grant permissions to any existing tables. This is used with RLS
        // todo@samuel refactor this along with the todo in TenantDatabaseManager
        // and move the grantPermissions() call inside the condition in `ManagesPostgresUsers::createUser()`
        // but maybe moving it inside $createUser is wrong because some central user may migrate new tables
        // while the RLS user should STILL get access to those tables
        foreach ($tables as $table) {
            $tableName = $table->table_name;

            /** @var string $primaryKey */
            $primaryKey = $this->connection()->selectOne(<<<SQL
                SELECT column_name
                FROM information_schema.key_column_usage
                WHERE table_name = '{$tableName}'
                AND constraint_name LIKE '%_pkey'
            SQL)->column_name;

            // Grant all permissions for all existing tables
            $this->connection()->statement("GRANT ALL ON \"{$schema}\".\"{$tableName}\" TO \"{$username}\"");

            // Grant permission to reference the primary key for the table
            // The previous query doesn't grant the references privilege, so it has to be granted here
            $this->connection()->statement("GRANT REFERENCES (\"{$primaryKey}\") ON \"{$schema}\".\"{$tableName}\" TO \"{$username}\"");
        }

        return true;
    }
}
