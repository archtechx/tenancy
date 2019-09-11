<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Illuminate\Database\DatabaseManager as BaseDatabaseManager;
use Illuminate\Foundation\Application;
use Stancl\Tenancy\Exceptions\DatabaseManagerNotRegisteredException;
use Stancl\Tenancy\Exceptions\TenantDatabaseAlreadyExistsException;
use Stancl\Tenancy\Jobs\QueuedTenantDatabaseCreator;

class DatabaseManagerv2
{
    /** @var string */
    public $originalDefaultConnectionName;

    /** @var Application */
    protected $app;

    /** @var BaseDatabaseManager */
    protected $database;

    public function __construct(Application $app, BaseDatabaseManager $database)
    {
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
        $connection = 'tenant'; // todo tenant-specific connections
        $this->createTenantConnection($tenant->getDatabaseName(), $connection);
        $this->switchConnection($connection);
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
    public function createTenantConnection(string $databaseName, string $connectionName = null)
    {
        $connectionName = $connectionName ?? 'tenant'; // todo

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

    public function switchConnection($connection)
    {
        $this->database->purge();
        $this->database->reconnect($connection);
        $this->database->setDefaultConnection($connection);
    }

    /**
     * Check if a tenant can be created.
     *
     * @param Tenant $tenant
     * @return true|TenantCannotBeCreatedException
     */
    public function canCreate(Tenant $tenant)
    {
        if ($this->getTenantDatabaseManager($tenant)->databaseExists($database = $tenant->getDatabaseName())) {
            return new TenantDatabaseAlreadyExistsException($database);
        }

        return true;
    }

    public function createDatabase(Tenant $tenant)
    {
        $database = $tenant->getDatabaseName();
        $manager = $this->getTenantDatabaseManager($tenant);

        if ($this->app['config']['tenancy.queue_database_creation'] ?? false) {
            QueuedTenantDatabaseCreator::dispatch($this->app[$manager], $database, 'create');
        } else {
            return $this->app[$manager]->createDatabase($database);
        }
    }

    public function deleteDatabase(Tenant $tenant)
    {
        $database = $tenant->getDatabaseName();
        $manager = $this->getTenantDatabaseManager($tenant);

        if ($this->app['config']['tenancy.queue_database_creation'] ?? false) {
            QueuedTenantDatabaseCreator::dispatch($this->app[$manager], $database, 'delete');
        } else {
            return $this->app[$manager]->deleteDatabase($database);
        }
    }

    protected function getTenantDatabaseManager(Tenant $tenant)
    {
        $connection = $tenant->getConnectionName(); // todo
        $driver = $this->getDriver($connection);

        $databaseManagers = $this->app['config']['tenancy.database_managers'];

        if (! array_key_exists($driver, $databaseManagers)) {
            throw new DatabaseManagerNotRegisteredException($driver);
        }

        return $databaseManagers[$driver];
    }
}
