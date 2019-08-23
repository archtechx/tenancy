<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Stancl\Tenancy\Jobs\QueuedTenantDatabaseCreator;
use Stancl\Tenancy\Jobs\QueuedTenantDatabaseDeleter;
use Illuminate\Database\DatabaseManager as BaseDatabaseManager;
use Stancl\Tenancy\Exceptions\DatabaseManagerNotRegisteredException;

final class DatabaseManager
{
    public $originalDefaultConnection;

    protected $defaultTenantConnectionName = 'tenant';

    public function __construct(BaseDatabaseManager $database)
    {
        $this->originalDefaultConnection = config('database.default');
        $this->database = $database;
    }

    public function connect(string $database, string $connectionName = null)
    {
        $this->createTenantConnection($database, $connectionName);
        $this->useConnection($connectionName);
    }

    public function connectToTenant($tenant, string $connectionName = null)
    {
        $this->connect(tenant()->getDatabaseName($tenant), $connectionName);
    }

    public function disconnect()
    {
        $default_connection = $this->originalDefaultConnection;
        $this->database->purge();
        $this->database->reconnect($default_connection);
        $this->database->setDefaultConnection($default_connection);
    }

    /**
     * Create a database.
     * @todo Should this handle prefixes?
     *
     * @param string $name
     * @param string $driver
     * @return bool
     */
    public function create(string $name, string $driver = null)
    {
        $this->createTenantConnection($name);
        $driver = $driver ?: $this->getDriver();

        $databaseManagers = config('tenancy.database_managers');

        if (! \array_key_exists($driver, $databaseManagers)) {
            throw new DatabaseManagerNotRegisteredException('Database could not be created', $driver);
        }

        if (config('tenancy.queue_database_creation', false)) {
            QueuedTenantDatabaseCreator::dispatch(app($databaseManagers[$driver]), $name, 'create');
        } else {
            return app($databaseManagers[$driver])->createDatabase($name);
        }
    }

    /**
     * Delete a database.
     * @todo Should this handle prefixes?
     *
     * @param string $name
     * @param string $driver
     * @return bool
     */
    public function delete(string $name, string $driver = null)
    {
        $this->createTenantConnection($name);
        $driver = $driver ?: $this->getDriver();

        $databaseManagers = config('tenancy.database_managers');

        if (! \array_key_exists($driver, $databaseManagers)) {
            throw new DatabaseManagerNotRegisteredException('Database could not be deleted', $driver);
        }

        if (config('tenancy.queue_database_deletion', false)) {
            QueuedTenantDatabaseDeleter::dispatch(app($databaseManagers[$driver]), $name, 'delete');
        } else {
            return app($databaseManagers[$driver])->deleteDatabase($name);
        }
    }

    public function getDriver($connectionName = null): ?string
    {
        $connectionName = $connectionName ?: $this->defaultTenantConnectionName;

        return config("database.connections.$connectionName.driver");
    }

    public function createTenantConnection(string $databaseName, string $connectionName = null)
    {
        $connectionName = $connectionName ?: $this->defaultTenantConnectionName;

        // Create the database connection.
        $based_on = config('tenancy.database.based_on') ?: config('database.default');
        config()->set([
            "database.connections.$connectionName" => config('database.connections.' . $based_on),
        ]);

        // Change DB name
        $databaseName = $this->getDriver($connectionName) === 'sqlite' ? database_path($databaseName) : $databaseName;
        config()->set(["database.connections.$connectionName.database" => $databaseName]);
    }

    public function useConnection(string $connection = null)
    {
        $connection = $connection ?: $this->defaultTenantConnectionName;

        $this->database->setDefaultConnection($connection);
        $this->database->reconnect($connection);
    }
}
