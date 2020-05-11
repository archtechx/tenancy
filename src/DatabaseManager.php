<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Closure;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager as BaseDatabaseManager;
use Illuminate\Foundation\Application;
use Stancl\Tenancy\Contracts\TenantCannotBeCreatedException;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Exceptions\DatabaseManagerNotRegisteredException;
use Stancl\Tenancy\Exceptions\TenantDatabaseAlreadyExistsException;
use Stancl\Tenancy\Jobs\QueuedTenantDatabaseCreator;
use Stancl\Tenancy\Jobs\QueuedTenantDatabaseDeleter;

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

        $this->setDefaultConnection($this->config->get('tenancy.central_connection'));
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

    /**
     * Create a database for a tenant.
     *
     * @param Tenant $tenant
     * @param ShouldQueue[]|callable[] $afterCreating
     * @return void
     * @throws DatabaseManagerNotRegisteredException
     */
    public function createDatabase(TenantWithDatabase $tenant, array $afterCreating = [])
    {
        // todo get rid of aftercreating logic
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
        $manager->createDatabase($tenant);

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
    public function deleteDatabase(TenantWithDatabase $tenant)
    {
        $database = $tenant->database()->getName();
        $manager = $tenant->database()->manager();

        $this->tenancy->event('database.deleting', $database, $tenant);

        if ($this->app['config']['tenancy.queue_database_deletion'] ?? false) {
            QueuedTenantDatabaseDeleter::dispatch($manager, $tenant);
        } else {
            $manager->deleteDatabase($tenant);
        }

        $this->tenancy->event('database.deleted', $database, $tenant);
    }
}
