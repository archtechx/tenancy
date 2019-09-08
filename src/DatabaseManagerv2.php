<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Illuminate\Foundation\Application;
use Illuminate\Database\DatabaseManager as BaseDatabaseManager;

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

    public function connect(Tenant $tenant)
    {
        $connection = 'tenant'; // todo tenant-specific connections
        $this->createTenantConnection($tenant->getDatabaseName(), $connection);
        $this->switchConnection($connection);
    }

    public function reconnect()
    {
        $this->switchConnection($this->originalDefaultConnectionName);
    }

    public function createTenantConnection(string $databaseName, string $connectionName = null)
    {
        $connectionName = $connectionName ?? 'tenant'; // todo

        // Create the database connection.
        $based_on = config('tenancy.database.based_on') ?? $this->originalDefaultConnectionName;
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

    /**
     * Check if a tenant can be created.
     *
     * @param Tenant $tenant
     * @return true|TenantCannotBeCreatedException
     */
    public function canCreate(Tenant $tenant)
    {
        // todo
    }
}
