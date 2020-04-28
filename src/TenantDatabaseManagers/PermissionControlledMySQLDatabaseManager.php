<?php

declare(strict_types=1);

namespace Stancl\Tenancy\TenantDatabaseManagers;

use Stancl\Tenancy\Contracts\ManagesDatabaseUsers;
use Stancl\Tenancy\Tenant;

class PermissionControlledMySQLDatabaseManager extends MySQLDatabaseManager implements ManagesDatabaseUsers
{
    public function createDatabase(string $name, Tenant $tenant): bool
    {
        parent::createDatabase($name, $tenant);

        $this->createDatabaseUser($name, $tenant);

        return true;
    }

    public function createDatabaseConnection(Tenant $tenant, array $baseConfiguration): array
    {
        return array_replace_recursive(
            parent::createDatabaseConnection($tenant, $baseConfiguration),
            array_filter([
                'host' => $tenant->getDatabaseHost(),
                'username' => $tenant->getDatabaseUsername(),
                'password' => $tenant->getDatabasePassword(),
                'port' => $tenant->getDatabasePort(),
                'url' => $tenant->getDatabaseUrl(),
            ])
        );
    }

    public function createDatabaseUser(string $databaseName, Tenant $tenant): void
    {
        $username = $tenant->generateDatabaseUsername();
        $password = $tenant->generateDatabasePassword();
        $appHost = $tenant->getDatabaseHost() ?? $this->getBaseConfigurationFor('host');

        $grants = implode(', ', $tenant->getDatabaseGrants());

        $this->database()->statement(
            "CREATE USER '$username'@'$appHost' IDENTIFIED BY '$password"
        );

        $this->database()->statement(
            "GRANT $grants ON $databaseName.* TO '$username'@'$appHost' IDENTIFIED BY '$password'"
        );

        $tenant->withData([
            '_tenancy_db_username' => $username,
            '_tenancy_db_password' => $password,
            '_tenancy_db_host' => $appHost,
            '_tenancy_db_link' => $tenant->getDatabaseLink(),
        ])->save();
    }

    private function getBaseConfigurationFor(string $key)
    {
        return $this->database()->getConfig($key);
    }
}
