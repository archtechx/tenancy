<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Illuminate\Database\DatabaseManager as BaseDatabaseManager;

class DatabaseManagerv2
{
    /** @var BaseDatabaseManager */
    protected $database;

    public function __construct(BaseDatabaseManager $database)
    {
        $this->database = $database;
    }

    public function connect(Tenant $tenant)
    {
        $connection = $tenant->getConnectionName(); // todo

        $this->createTenantConnection($tenant->getDatabaseName(), $connection);
        $this->switchConnection($connection);
    }

    public function reconnect()
    {
        $this->switchConnection($this->originalDefaultConnection);
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

    public function switchConnection($connection)
    {
        $this->database->purge();
        $this->database->reconnect($connection);
        $this->database->setDefaultConnection($connection);
    }
}
