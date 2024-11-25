<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\TenantDatabaseManagers;

use Stancl\Tenancy\Database\Concerns\CreatesDatabaseUsers;
use Stancl\Tenancy\Database\Contracts\ManagesDatabaseUsers;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\DatabaseConfig;

class PermissionControlledMicrosoftSQLServerDatabaseManager extends MicrosoftSQLDatabaseManager implements ManagesDatabaseUsers
{
    use CreatesDatabaseUsers;

    /** @var string[] */
    public static array $grants = [
        'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'EXECUTE',
    ];

    public function createUser(DatabaseConfig $databaseConfig): bool
    {
        $database = $databaseConfig->getName();
        $username = $databaseConfig->getUsername();
        $password = $databaseConfig->getPassword();

        // Create login
        $this->connection()->statement("CREATE LOGIN [$username] WITH PASSWORD = '$password'");

        // Create user in the database
        // Grant the user permissions specified in the $grants array
        // The 'CONNECT' permission is granted automatically
        $grants = implode(', ', static::$grants);

        return $this->connection()->statement("USE [$database]; CREATE USER [$username] FOR LOGIN [$username]; GRANT $grants TO [$username]");
    }

    public function deleteUser(DatabaseConfig $databaseConfig): bool
    {
        return $this->connection()->statement("DROP LOGIN [{$databaseConfig->getUsername()}]");
    }

    public function userExists(string $username): bool
    {
        return (bool) $this->connection()->select("SELECT sp.name as username FROM sys.server_principals sp WHERE sp.name = '{$username}'");
    }

    public function makeConnectionConfig(array $baseConfig, string $databaseName): array
    {
        $baseConfig['database'] = $databaseName;

        return $baseConfig;
    }

    public function deleteDatabase(TenantWithDatabase $tenant): bool
    {
        // Close all connections to the database before deleting it
        // Set the database to SINGLE_USER mode to ensure that
        // No other connections are using the database while we're trying to delete it
        // Rollback all active transactions
        $this->connection()->statement("ALTER DATABASE [{$tenant->database()->getName()}] SET SINGLE_USER WITH ROLLBACK IMMEDIATE;");

        return parent::deleteDatabase($tenant);
    }
}
