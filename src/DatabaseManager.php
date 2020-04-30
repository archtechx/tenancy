<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager as BaseDatabaseManager;
use Illuminate\Foundation\Application;
use Stancl\Tenancy\Contracts\ManagesDatabaseUsers;
use Stancl\Tenancy\Contracts\TenantCannotBeCreatedException;
use Stancl\Tenancy\Exceptions\DatabaseManagerNotRegisteredException;
use Stancl\Tenancy\Exceptions\TenantDatabaseAlreadyExistsException;
use Stancl\Tenancy\Jobs\QueuedTenantDatabaseCreator;
use Stancl\Tenancy\Jobs\QueuedTenantDatabaseDeleter;

/**
 * @internal Class is subject to breaking changes in minor and patch versions.
 */
class DatabaseManager
{
    /** @var string */
    public static $originalDefaultConnectionName;

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
        static::$originalDefaultConnectionName = $app['config']['database.default'];
    }

    /**
     * Set the TenantManager instance, used to dispatch tenancy events.
     */
    public function withTenantManager(TenantManager $tenantManager): self
    {
        $this->tenancy = $tenantManager;

        return $this;
    }

    /**
     * Connect to a tenant's database.
     */
    public function connect(Tenant $tenant)
    {
        $this->createTenantConnection($tenant, $tenant->database()->getTemplateConnectionName());
        $this->setDefaultConnection($tenant->database()->getTemplateConnectionName());
        $this->switchConnection($tenant->database()->getTemplateConnectionName());
    }

    /**
     * Reconnect to the default non-tenant connection.
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
     */
    public function setDefaultConnection(string $connection)
    {
        $this->app['config']['database.default'] = $connection;
    }

    /**
     * Create the tenant database connection.
     */
    public function createTenantConnection(Tenant $tenant, $connectionName)
    {
        $this->app['config']["database.connections.$connectionName"] = $tenant->database()->connection();
    }

    /**
     * Switch the application's connection.
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
     * @throws TenantCannotBeCreatedException
     * @throws DatabaseManagerNotRegisteredException
     * @throws TenantDatabaseAlreadyExistsException
     */
    public function ensureTenantCanBeCreated(Tenant $tenant): void
    {
        if ($tenant->database()->manager()->databaseExists($database = $tenant->database()->getName())) {
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
        $tenant->database()->makeCredentials();

        $afterCreating = array_merge(
            $afterCreating,
            $this->tenancy->event('database.creating', $tenant->database()->getName(), $tenant)
        );

        if ($this->app['config']['tenancy.queue_database_creation'] ?? false) {
            $this->createDatabaseAsynchronously($tenant, $afterCreating);
        } else {
            $this->createDatabaseSynchronously($tenant, $afterCreating);
        }

        $this->tenancy->event('database.created', $tenant->database()->getName(), $tenant);
    }

    protected function createDatabaseAsynchronously(Tenant $tenant, array $afterCreating)
    {
        $chain = [];
        foreach ($afterCreating as $item) {
            if (is_string($item) && class_exists($item)) {
                $chain[] = new $item($tenant); // Classes are instantiated and given $tenant
            } elseif ($item instanceof ShouldQueue) {
                $chain[] = $item;
            }
        }

        QueuedTenantDatabaseCreator::withChain($chain)->dispatch($tenant->database()->manager(), $tenant);
    }

    protected function createDatabaseSynchronously(Tenant $tenant, array $afterCreating)
    {
        $manager = $tenant->database()->manager();
        $manager->createDatabase($tenant->database()->getName());

        if ($manager instanceof ManagesDatabaseUsers) {
            $manager->createUser($tenant->database());
        }

        foreach ($afterCreating as $item) {
            if (is_object($item) && ! $item instanceof Closure) {
                $item->handle($tenant);
            } else {
                $item($tenant);
            }
        }
    }

    /**
     * Delete a tenant's database.
     *
     * @throws DatabaseManagerNotRegisteredException
     */
    public function deleteDatabase(Tenant $tenant)
    {
        $database = $tenant->database()->getName();
        $manager = $tenant->database()->manager();

        $this->tenancy->event('database.deleting', $database, $tenant);

        if ($this->app['config']['tenancy.queue_database_deletion'] ?? false) {
            QueuedTenantDatabaseDeleter::dispatch($manager, $tenant);
        } else {
            $manager->deleteDatabase($database);
            if ($manager instanceof ManagesDatabaseUsers) {
                $manager->deleteUser($tenant->database());
            }
        }

        $this->tenancy->event('database.deleted', $database, $tenant);
    }
}
