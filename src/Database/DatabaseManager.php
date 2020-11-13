<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager as BaseDatabaseManager;
use Stancl\Tenancy\Contracts\TenantCannotBeCreatedException;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Exceptions\DatabaseManagerNotRegisteredException;
use Stancl\Tenancy\Exceptions\TenantDatabaseAlreadyExistsException;

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
    public function connectToTenant(TenantWithDatabase $tenant)
    {
        $this->database->purge('tenant');
        $this->createTenantConnection($tenant);
        $this->setDefaultConnection('tenant');
    }

    /**
     * Reconnect to the default non-tenant connection.
     */
    public function reconnectToCentral()
    {
        if (tenancy()->initialized) {
            $this->database->purge('tenant');
        }

        $this->setDefaultConnection($this->config->get('tenancy.database.central_connection'));
    }

    /**
     * Change the default database connection config.
     */
    public function setDefaultConnection(string $connection)
    {
        $this->app['config']['database.default'] = $connection;
        $this->database->setDefaultConnection($connection);
    }

    /**
     * Create the tenant database connection.
     */
    public function createTenantConnection(TenantWithDatabase $tenant)
    {
        $this->app['config']['database.connections.tenant'] = $tenant->database()->connection();
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
        if ($tenant->database()->manager()->databaseExists($database = $tenant->database()->getName())) {
            throw new TenantDatabaseAlreadyExistsException($database);
        }
    }
}
