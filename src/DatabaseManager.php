<?php

namespace Stancl\Tenancy;

use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Jobs\QueuedTenantDatabaseCreator;
use Stancl\Tenancy\Jobs\QueuedTenantDatabaseDeleter;
use Illuminate\Database\DatabaseManager as BaseDatabaseManager;

class DatabaseManager
{
    public function __construct(BaseDatabaseManager $database)
    {
        $this->database = $database;
    }

    public function connect(string $database)
    {
        $this->createTenantConnection($database);
        $this->database->setDefaultConnection('tenant');
        $this->database->reconnect('tenant');
    }

    public function connectToTenant($tenant)
    {
        $this->connect(tenant()->getDatabaseName($tenant));
    }

    public function disconnect()
    {
        $this->database->reconnect('default');
        $this->database->setDefaultConnection('default');
    }

    public function create(string $name, string $driver = null)
    {
        $this->createTenantConnection($name);
        $driver = $driver ?: $this->getDriver();

        $databaseManagers = config('tenancy.database_managers');

        if (! array_key_exists($driver, $databaseManagers)) {
            throw new \Exception("Database could not be created: no database manager for driver $driver is registered.");
        }

        if (config('tenancy.queue_database_creation', false)) {
            QueuedTenantDatabaseCreator::dispatch(app($databaseManagers[$driver]), $name, 'create');
        } else {
            app($databaseManagers[$driver])->createDatabase($name);
        }
    }

    public function delete(string $name, string $driver = null)
    {
        $this->createTenantConnection($name);
        $driver = $driver ?: $this->getDriver();

        $databaseManagers = config('tenancy.database_managers');

        if (! array_key_exists($driver, $databaseManagers)) {
            throw new \Exception("Database could not be deleted: no database manager for driver $driver is registered.");
        }

        if (config('tenancy.queue_database_deletion', false)) {
            QueuedTenantDatabaseDeleter::dispatch(app($databaseManagers[$driver]), $name, 'delete');
        } else {
            app($databaseManagers[$driver])->deleteDatabase($name);
        }
    }

    public function getDriver(): ?string
    {
        return config("database.connections.tenant.driver");
    }

    public function createTenantConnection(string $database_name)
    {
        // Create the `tenancy` database connection.
        $based_on = config('tenancy.database.based_on') ?: config('database.default');
        config()->set([
            'database.connections.tenant' => config('database.connections.' . $based_on)
        ]);

        // Change DB name
        $database_name = $this->getDriver() === "sqlite" ? database_path($database_name) : $database_name;
        config()->set(['database.connections.tenant.database' => $database_name]);
    }
}
