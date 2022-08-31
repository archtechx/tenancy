<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager as BaseDatabaseManager;
use Stancl\Tenancy\Contracts\TenantCannotBeCreatedException;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;

/**
 * @internal Class is subject to breaking changes in minor and patch versions.
 */
class DatabaseManager
{
    /** @var Application */
    protected $app;

    /** @var BaseDatabaseManager */
    protected $database;

    /** @var Repository */
    protected $config;

    public function __construct(Application $app, BaseDatabaseManager $database, Repository $config)
    {
        $this->app = $app;
        $this->database = $database;
        $this->config = $config;
    }

    /**
     * Connect to a tenant's database.
     */
    public function connectToTenant(TenantWithDatabase $tenant): void
    {
        $this->purgeTenantConnection();
        $this->createTenantConnection($tenant);
        $this->setDefaultConnection('tenant');
    }

    /**
     * Reconnect to the default non-tenant connection.
     */
    public function reconnectToCentral(): void
    {
        $this->purgeTenantConnection();
        $this->setDefaultConnection($this->config->get('tenancy.database.central_connection'));
    }

    /**
     * Change the default database connection config.
     */
    public function setDefaultConnection(string $connection): void
    {
        $this->config['database.default'] = $connection;
        $this->database->setDefaultConnection($connection);
    }

    /**
     * Create the tenant database connection.
     */
    public function createTenantConnection(TenantWithDatabase $tenant): void
    {
        $this->config['database.connections.tenant'] = $tenant->database()->connection();
    }

    /**
     * Purge the tenant database connection.
     */
    public function purgeTenantConnection(): void
    {
        if (array_key_exists('tenant', $this->database->getConnections())) {
            $this->database->purge('tenant');
        }

        unset($this->config['database.connections.tenant']);
    }

    /**
     * Check if a tenant can be created.
     *
     * @throws TenantCannotBeCreatedException
     * @throws DatabaseManagerNotRegisteredException
     * @throws TenantDatabaseAlreadyExistsException
     */
    public function ensureTenantCanBeCreated(TenantWithDatabase $tenant): void
    {
        $manager = $tenant->database()->manager();

        if ($manager->databaseExists($database = $tenant->database()->getName())) {
            throw new Exceptions\TenantDatabaseAlreadyExistsException($database);
        }

        if ($manager instanceof Contracts\ManagesDatabaseUsers && $manager->userExists($username = $tenant->database()->getUsername())) {
            throw new Exceptions\TenantDatabaseUserAlreadyExistsException($username);
        }
    }
}
