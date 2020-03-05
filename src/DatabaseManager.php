<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager as BaseDatabaseManager;
use Illuminate\Foundation\Application;
use Stancl\Tenancy\Contracts\Future\CanSetConnection;
use Stancl\Tenancy\Contracts\TenantCannotBeCreatedException;
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

    /** @var TenantManager */
    protected $tenancy;

    public function __construct(Application $app, BaseDatabaseManager $database)
    {
        $this->app = $app;
        $this->database = $database;
        $this->originalDefaultConnectionName = $app['config']['database.default'];
    }

    /**
     * Set the TenantManager instance, used to dispatch tenancy events.
     *
     * @param TenantManager $tenantManager
     * @return self
     */
    public function withTenantManager(TenantManager $tenantManager): self
    {
        $this->tenancy = $tenantManager;

        return $this;
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
        $this->setDefaultConnection($tenant->getConnectionName());
        $this->switchConnection($tenant->getConnectionName());
    }

    /**
     * Reconnect to the default non-tenant connection.
     *
     * @return void
     */
    public function reconnect()
    {
        // Opposite order to connect() because we don't
        // want to ever purge the central connection
        $this->switchConnection($this->originalDefaultConnectionName);
        $this->setDefaultConnection($this->originalDefaultConnectionName);
    }

    /**
     * Change the default database connection config.
     *
     * @param string $connection
     * @return void
     */
    public function setDefaultConnection(string $connection)
    {
        $this->app['config']['database.default'] = $connection;
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
        $based_on = $this->getBaseConnection($connectionName);
        $this->app['config']["database.connections.$connectionName"] = $this->app['config']['database.connections.' . $based_on];

        // Change database name.
        $databaseName = $this->getDriver($connectionName) === 'sqlite' ? database_path($databaseName) : $databaseName;
        $separateBy = $this->separateBy($connectionName);

        $this->app['config']["database.connections.$connectionName.$separateBy"] = $databaseName;
    }

    /**
     * Get the name of the connection that $connectionName should be based on.
     *
     * @param string $connectionName
     * @return string
     */
    public function getBaseConnection(string $connectionName): string
    {
        return ($connectionName !== 'tenant' ? $connectionName : null) // 'tenant' is not a specific connection, it's the default
            ?? $this->app['config']['tenancy.database.based_on']
            ?? $this->originalDefaultConnectionName; // tenancy.database.based_on === null => use the default connection
    }

    /**
     * Get the driver of a database connection.
     *
     * @param string $connectionName
     * @return string|null
     */
    public function getDriver(string $connectionName): ?string
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
     * @throws DatabaseManagerNotRegisteredException
     * @throws TenantDatabaseAlreadyExistsException
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
     * @param ShouldQueue[]|callable[] $afterCreating
     * @return void
     * @throws DatabaseManagerNotRegisteredException
     */
    public function createDatabase(Tenant $tenant, array $afterCreating = [])
    {
        $database = $tenant->getDatabaseName();
        $manager = $this->getTenantDatabaseManager($tenant);

        $afterCreating = array_merge(
            $afterCreating,
            $this->tenancy->event('database.creating', $database, $tenant)
        );

        if ($this->app['config']['tenancy.queue_database_creation'] ?? false) {
            $chain = [];
            foreach ($afterCreating as $item) {
                if (is_string($item) && class_exists($item)) {
                    $chain[] = new $item($tenant); // Classes are instantiated and given $tenant
                } elseif ($item instanceof ShouldQueue) {
                    $chain[] = $item;
                }
            }

            QueuedTenantDatabaseCreator::withChain($chain)->dispatch($manager, $database);
        } else {
            $manager->createDatabase($database);
            foreach ($afterCreating as $item) {
                if (is_object($item) && ! $item instanceof Closure) {
                    $item->handle($tenant);
                } else {
                    $item($tenant);
                }
            }
        }

        $this->tenancy->event('database.created', $database, $tenant);
    }

    /**
     * Delete a tenant's database.
     *
     * @param Tenant $tenant
     * @return void
     * @throws DatabaseManagerNotRegisteredException
     */
    public function deleteDatabase(Tenant $tenant)
    {
        $database = $tenant->getDatabaseName();
        $manager = $this->getTenantDatabaseManager($tenant);

        $this->tenancy->event('database.deleting', $database, $tenant);

        if ($this->app['config']['tenancy.queue_database_deletion'] ?? false) {
            QueuedTenantDatabaseDeleter::dispatch($manager, $database);
        } else {
            $manager->deleteDatabase($database);
        }

        $this->tenancy->event('database.deleted', $database, $tenant);
    }

    /**
     * Get the TenantDatabaseManager for a tenant's database connection.
     *
     * @param Tenant $tenant
     * @return TenantDatabaseManager
     * @throws DatabaseManagerNotRegisteredException
     */
    public function getTenantDatabaseManager(Tenant $tenant): TenantDatabaseManager
    {
        $driver = $this->getDriver($this->getBaseConnection($connectionName = $tenant->getConnectionName()));

        $databaseManagers = $this->app['config']['tenancy.database_managers'];

        if (! array_key_exists($driver, $databaseManagers)) {
            throw new DatabaseManagerNotRegisteredException($driver);
        }

        $databaseManager = $this->app[$databaseManagers[$driver]];

        if ($connectionName !== 'tenant' && $databaseManager instanceof CanSetConnection) {
            $databaseManager->setConnection($connectionName);
        }

        return $databaseManager;
    }

    /**
     * What key on the connection config should be used to separate tenants.
     *
     * @param string $connectionName
     * @return string
     */
    public function separateBy(string $connectionName): string
    {
        if ($this->getDriver($this->getBaseConnection($connectionName)) === 'pgsql'
            && $this->app['config']['tenancy.database.separate_by'] === 'schema') {
            return 'schema';
        }

        return 'database';
    }
}
