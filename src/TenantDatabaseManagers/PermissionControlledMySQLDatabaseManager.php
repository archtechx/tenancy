<?php

declare(strict_types=1);

namespace Stancl\Tenancy\TenantDatabaseManagers;

use Stancl\Tenancy\Contracts\ManagesDatabaseUsers;
use Stancl\Tenancy\DatabaseConfig;
use Stancl\Tenancy\Traits\CreatesDatabaseUsers;

class PermissionControlledMySQLDatabaseManager extends MySQLDatabaseManager implements ManagesDatabaseUsers
{
    use CreatesDatabaseUsers;

    public static $grants = [
        'ALTER', 'ALTER ROUTINE', 'CREATE', 'CREATE ROUTINE', 'CREATE TEMPORARY TABLES', 'CREATE VIEW',
        'DELETE', 'DROP', 'EVENT', 'EXECUTE', 'INDEX', 'INSERT', 'LOCK TABLES', 'REFERENCES', 'SELECT',
        'SHOW VIEW', 'TRIGGER', 'UPDATE',
    ];

    public function createUser(DatabaseConfig $databaseConfig): bool
    {
        $database = $databaseConfig->getName();
        $username = $databaseConfig->getUsername();
        $hostname = $databaseConfig->connection()['host'];
        $password = $databaseConfig->getPassword();

        $this->database()->statement("CREATE USER `{$username}`@`{$hostname}` IDENTIFIED BY `{$password}`");

        $grants = implode(', ', static::$grants);

        if ($this->isVersion8()) { // MySQL 8+
            $grantQuery = "GRANT $grants ON `$database`.* TO `$username`@`$hostname`";
        } else { // MySQL 5.7
            $grantQuery = "GRANT $grants ON $database.* TO `$username`@`$hostname` IDENTIFIED BY '$password'";
        }

        return $this->database()->statement($grantQuery);
    }

    protected function isVersion8(): bool
    {
        $version = $this->database()->select($this->db->raw('select version()'))[0]->{'version()'};

        return version_compare($version, '8.0.0') >= 0;
    }

    public function deleteUser(DatabaseConfig $databaseConfig): bool
    {
        return $this->database()->statement("DROP USER IF EXISTS '{$databaseConfig->username}'");
    }
}
