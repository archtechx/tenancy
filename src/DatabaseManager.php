<?php

namespace Stancl\Tenancy;

use Illuminate\Support\Facades\DB;
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
        if ($driver === "sqlite") {
            $f = fopen(database_path($name), 'w');
            fclose($f);
            
            return;
        }

        return DB::statement("CREATE DATABASE `$name`");
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
