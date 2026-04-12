<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\TenantDatabaseManagers;

use Stancl\Tenancy\Database\Concerns\CreatesDatabaseUsers;
use Stancl\Tenancy\Database\Contracts\ManagesDatabaseUsers;
use Stancl\Tenancy\Database\DatabaseConfig;

class PermissionControlledMySQLDatabaseManager extends MySQLDatabaseManager implements ManagesDatabaseUsers
{
    use CreatesDatabaseUsers;

    /** @var string[] */
    public static array $grants = [
        'ALTER', 'ALTER ROUTINE', 'CREATE', 'CREATE ROUTINE', 'CREATE TEMPORARY TABLES', 'CREATE VIEW',
        'DELETE', 'DROP', 'EVENT', 'EXECUTE', 'INDEX', 'INSERT', 'LOCK TABLES', 'REFERENCES', 'SELECT',
        'SHOW VIEW', 'TRIGGER', 'UPDATE',
    ];

    public function createUser(DatabaseConfig $databaseConfig): bool
    {
        $database = $databaseConfig->getName();
        $username = $databaseConfig->getUsername();
        $password = $databaseConfig->getPassword();

        $this->connection()->statement("CREATE USER `{$username}`@`%` IDENTIFIED BY '{$password}'");

        $grants = implode(', ', static::$grants);

        if ($this->isVersion8()) { // MySQL 8+
            $grantQuery = "GRANT $grants ON `$database`.* TO `$username`@`%`";
        } else { // MySQL 5.7
            $grantQuery = "GRANT $grants ON `$database`.* TO `$username`@`%` IDENTIFIED BY '$password'";
        }

        return $this->connection()->statement($grantQuery);
    }

    protected function isVersion8(): bool
    {
        $versionSelect = (string) $this->connection()->raw('select version()')->getValue($this->connection()->getQueryGrammar());
        $version = $this->connection()->select($versionSelect)[0]->{'version()'};

        return version_compare($version, '8.0.0') >= 0;
    }

    public function deleteUser(DatabaseConfig $databaseConfig): bool
    {
        return $this->connection()->statement("DROP USER IF EXISTS '{$databaseConfig->getUsername()}'");
    }

    public function userExists(string $username): bool
    {
        return (bool) $this->connection()->select("SELECT count(*) FROM mysql.user WHERE user = '$username'")[0]->{'count(*)'};
    }
}
