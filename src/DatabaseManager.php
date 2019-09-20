<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Illuminate\Database\DatabaseManager as BaseDatabaseManager;
use Illuminate\Foundation\Application;
use Stancl\Tenancy\Contracts\TenantDatabaseManager;
use Stancl\Tenancy\Exceptions\DatabaseManagerNotRegisteredException;
use Stancl\Tenancy\Exceptions\TenantDatabaseAlreadyExistsException;
use Stancl\Tenancy\Jobs\QueuedTenantDatabaseCreator;
use Stancl\Tenancy\Jobs\QueuedTenantDatabaseDeleter;

class DatabaseManager
{
    /** @var string */
    public $originalDefaultConnectionName;

    /** @var Application */
    protected $app;

    /** @var BaseDatabaseManager */
    protected $database;

    public function __construct(Application $app, BaseDatabaseManager $database)
    {
        $this->app = $app;
        $this->database = $database;
        $this->originalDefaultConnectionName = $app['config']['database.default'];
    }

    /**
     * Connect to a tenant's database.
     *
     * @param Tenant $tenant
     * @return void
     */
    public function connect(Tenant $tenant)
    {
        $this->createTenantConnection($tenant->getDatabaseName(), $tenant->getConnectionName());
        $this->switchConnection($tenant->getConnectionName());
    }

    /**
     * Reconnect to the default non-tenant connection.
     *
     * @return void
     */
    public function reconnect()
    {
        $this->switchConnection($this->originalDefaultConnectionName);
    }

    /**
     * Create the tenant database connection.
     *
     * @param string $databaseName
     * @param string $connectionName
     * @return void
     */
    public function createTenantConnection($databaseName, $connectionName)
    {
        // Create the database connection.
        $based_on = $this->app['config']['tenancy.database.based_on'] ?? $this->originalDefaultConnectionName;
        $this->app['config']["database.connections.$connectionName"] = $this->app['config']['database.connections.' . $based_on];

        // Change database name.
        $databaseName = $this->getDriver($connectionName) === 'sqlite' ? database_path($databaseName) : $databaseName;
        $this->app['config']["database.connections.$connectionName.database"] = $databaseName;
    }

    /**
     * Get the driver of a database connection.
     *
     * @param string $connectionName
     * @return string
     */
    protected function getDriver(string $connectionName): string
    {
        return $this->app['config']["database.connections.$connectionName.driver"];
    }

    /**
     * Switch the application's connection.
     *
     * @param string $connection
     * @return void
     */
    public function switchConnection(string $connection)
    {
        $this->app['config']['database.default'] = $connection;
        $this->database->purge();
        $this->database->reconnect($connection);
        $this->database->setDefaultConnection($connection);
    }

    /**
     * Check if a tenant can be created.
     *
     * @param Tenant $tenant
     * @return void
     * @throws TenantCannotBeCreatedException
     */
    public function ensureTenantCanBeCreated(Tenant $tenant): void
    {
        if ($this->getTenantDatabaseManager($tenant)->databaseExists($database = $tenant->getDatabaseName())) {
            throw new TenantDatabaseAlreadyExistsException($database);
        }
    }

    /**
     * Create a database for a tenant.
     *
     * @param Tenant $tenant
     * @return void
     */
    public function createDatabase(Tenant $tenant)
    {
        $database = $tenant->getDatabaseName();
        $manager = $this->getTenantDatabaseManager($tenant);

        if ($this->app['config']['tenancy.queue_database_creation'] ?? false) {
            QueuedTenantDatabaseCreator::dispatch($manager, $database);
        } else {
            return $manager->createDatabase($database);
        }
    }

    /**
     * Delete a tenant's database.
     *
     * @param Tenant $tenant
     * @return void
     */
    public function deleteDatabase(Tenant $tenant)
    {
        $database = $tenant->getDatabaseName();
        $manager = $this->getTenantDatabaseManager($tenant);

        if ($this->app['config']['tenancy.queue_database_deletion'] ?? false) {
            QueuedTenantDatabaseDeleter::dispatch($manager, $database);
        } else {
            return $manager->deleteDatabase($database);
        }
    }

    /**
     * Get the TenantDatabaseManager for a tenant's database connection.
     *
     * @param Tenant $tenant
     * @return TenantDatabaseManager
     */
    protected function getTenantDatabaseManager(Tenant $tenant): TenantDatabaseManager
    {
        // todo2 this shouldn't have to create a connection
        $this->createTenantConnection($tenant->getDatabaseName(), $tenant->getConnectionName());
        $driver = $this->getDriver($tenant->getConnectionName());

        $databaseManagers = $this->app['config']['tenancy.database_managers'];

        if (! array_key_exists($driver, $databaseManagers)) {
            throw new DatabaseManagerNotRegisteredException($driver);
        }

        return $this->app[$databaseManagers[$driver]];
    }
}
