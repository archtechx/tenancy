<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Exception;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\ForwardsCalls;
use Stancl\Tenancy\Contracts\Future\CanFindByAnyKey;
use Stancl\Tenancy\Contracts\TenantCannotBeCreatedException;
use Stancl\Tenancy\Exceptions\NotImplementedException;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedException;
use Stancl\Tenancy\Jobs\QueuedTenantDatabaseMigrator;
use Stancl\Tenancy\Jobs\QueuedTenantDatabaseSeeder;

/**
 * @internal Class is subject to breaking changes in minor and patch versions.
 */
class TenantManager
{
    use ForwardsCalls;

    /** @var Tenant The current tenant. */
    protected $tenant;

    /** @var Application */
    protected $app;

    /** @var ConsoleKernel */
    protected $artisan;

    /** @var Contracts\StorageDriver */
    public $storage;

    /** @var DatabaseManager */
    public $database;

    /** @var callable[][] */
    protected $eventListeners = [];

    /** @var bool Has tenancy been initialized. */
    public $initialized = false;

    public function __construct(Application $app, ConsoleKernel $artisan, Contracts\StorageDriver $storage, DatabaseManager $database)
    {
        $this->app = $app;
        $this->storage = $storage;
        $this->artisan = $artisan;
        $this->database = $database->withTenantManager($this);

        $this->bootstrapFeatures();
    }

    /**
     * Write a new tenant to storage.
     *
     * @param Tenant $tenant
     * @return self
     */
    public function createTenant(Tenant $tenant): self
    {
        $this->event('tenant.creating', $tenant);

        $this->ensureTenantCanBeCreated($tenant);

        $this->storage->createTenant($tenant);

        $tenant->persisted = true;

        /** @var \Illuminate\Contracts\Queue\ShouldQueue[]|callable[] $afterCreating */
        $afterCreating = [];

        if ($this->shouldMigrateAfterCreation()) {
            $afterCreating[] = $this->databaseCreationQueued()
                ? new QueuedTenantDatabaseMigrator($tenant, $this->getMigrationParameters())
                : function () use ($tenant) {
                    $this->artisan->call('tenants:migrate', [
                        '--tenants' => [$tenant['id']],
                    ] + $this->getMigrationParameters());
                };
        }

        if ($this->shouldSeedAfterMigration()) {
            $afterCreating[] = $this->databaseCreationQueued()
                ? new QueuedTenantDatabaseSeeder($tenant)
                : function () use ($tenant) {
                    $this->artisan->call('tenants:seed', [
                        '--tenants' => [$tenant['id']],
                    ]);
                };
        }

        if ($this->shouldCreateDatabase($tenant)) {
            $this->database->createDatabase($tenant, $afterCreating);
        }

        $this->event('tenant.created', $tenant);

        return $this;
    }

    /**
     * Delete a tenant from storage.
     *
     * @param Tenant $tenant
     * @return self
     */
    public function deleteTenant(Tenant $tenant): self
    {
        $this->event('tenant.deleting', $tenant);

        $this->storage->deleteTenant($tenant);

        if ($this->shouldDeleteDatabase()) {
            $this->database->deleteDatabase($tenant);
        }

        $this->event('tenant.deleted', $tenant);

        return $this;
    }

    /**
     * Alias for Stancl\Tenancy\Tenant::create.
     *
     * @param string|string[] $domains
     * @param array $data
     * @return Tenant
     */
    public static function create($domains, array $data = []): Tenant
    {
        return Tenant::create($domains, $data);
    }

    /**
     * Ensure that a tenant can be created.
     *
     * @param Tenant $tenant
     * @return void
     * @throws TenantCannotBeCreatedException
     */
    public function ensureTenantCanBeCreated(Tenant $tenant): void
    {
        if ($this->shouldCreateDatabase($tenant)) {
            $this->database->ensureTenantCanBeCreated($tenant);
        }

        $this->storage->ensureTenantCanBeCreated($tenant);
    }

    /**
     * Update an existing tenant in storage.
     *
     * @param Tenant $tenant
     * @return self
     */
    public function updateTenant(Tenant $tenant): self
    {
        $this->event('tenant.updating', $tenant);

        $this->storage->updateTenant($tenant);

        $this->event('tenant.updated', $tenant);

        return $this;
    }

    /**
     * Find tenant by domain & initialize tenancy.
     *
     * @param string|null $domain
     * @return self
     */
    public function init(string $domain = null): self
    {
        $domain = $domain ?? request()->getHost();
        $this->initializeTenancy($this->findByDomain($domain));

        return $this;
    }

    /**
     * Find tenant by ID & initialize tenancy.
     *
     * @param string $id
     * @return self
     */
    public function initById(string $id): self
    {
        $this->initializeTenancy($this->find($id));

        return $this;
    }

    /**
     * Find a tenant using an id.
     *
     * @param string $id
     * @return Tenant
     * @throws TenantCouldNotBeIdentifiedException
     */
    public function find(string $id): Tenant
    {
        return $this->storage->findById($id);
    }

    /**
     * Find a tenant using a domain name.
     *
     * @param string $id
     * @return Tenant
     * @throws TenantCouldNotBeIdentifiedException
     */
    public function findByDomain(string $domain): Tenant
    {
        return $this->storage->findByDomain($domain);
    }

    /**
     * Find a tenant using an arbitrary key.
     *
     * @param string $key
     * @param mixed $value
     * @return Tenant
     * @throws TenantCouldNotBeIdentifiedException
     * @throws NotImplementedException
     */
    public function findBy(string $key, $value): Tenant
    {
        if ($key === null) {
            throw new Exception('No key supplied.');
        }

        if ($value === null) {
            throw new Exception('No value supplied.');
        }

        if (! $this->storage instanceof CanFindByAnyKey) {
            throw new NotImplementedException(get_class($this->storage), 'findBy',
                'This method was added to the DB storage driver provided by the package in 2.2.0 and might be part of the StorageDriver contract in 3.0.0.'
            );
        }

        return $this->storage->findBy($key, $value);
    }

    /**
     * Get all tenants.
     *
     * @param Tenant[]|string[] $only
     * @return Collection<Tenant>
     */
    public function all($only = []): Collection
    {
        $only = array_map(function ($item) {
            return $item instanceof Tenant ? $item->id : $item;
        }, (array) $only);

        return collect($this->storage->all($only));
    }

    /**
     * Initialize tenancy.
     *
     * @param Tenant $tenant
     * @return self
     */
    public function initializeTenancy(Tenant $tenant): self
    {
        if ($this->initialized) {
            $this->endTenancy();
        }

        $this->setTenant($tenant);
        $this->bootstrapTenancy($tenant);
        $this->initialized = true;

        return $this;
    }

    /** @alias initializeTenancy */
    public function initialize(Tenant $tenant): self
    {
        return $this->initializeTenancy($tenant);
    }

    /**
     * Execute TenancyBootstrappers.
     *
     * @param Tenant $tenant
     * @return self
     */
    public function bootstrapTenancy(Tenant $tenant): self
    {
        $prevented = $this->event('bootstrapping', $tenant);

        foreach ($this->tenancyBootstrappers($prevented) as $bootstrapper) {
            $this->app[$bootstrapper]->start($tenant);
        }

        $this->event('bootstrapped', $tenant);

        return $this;
    }

    public function endTenancy(): self
    {
        if (! $this->initialized) {
            return $this;
        }

        $prevented = $this->event('ending', $this->tenant);

        foreach ($this->tenancyBootstrappers($prevented) as $bootstrapper) {
            $this->app[$bootstrapper]->end();
        }

        $this->initialized = false;
        $this->tenant = null;

        $this->event('ended');

        return $this;
    }

    /** @alias endTenancy */
    public function end(): self
    {
        return $this->endTenancy();
    }

    /**
     * Get the current tenant.
     *
     * @param string $key
     * @return Tenant|null|mixed
     */
    public function getTenant(string $key = null)
    {
        if (! $this->tenant) {
            return;
        }

        if (! is_null($key)) {
            return $this->tenant[$key];
        }

        return $this->tenant;
    }

    protected function setTenant(Tenant $tenant): self
    {
        $this->tenant = $tenant;

        return $this;
    }

    protected function bootstrapFeatures(): self
    {
        foreach ($this->app['config']['tenancy.features'] as $feature) {
            $this->app[$feature]->bootstrap($this);
        }

        return $this;
    }

    /**
     * Return a list of TenancyBootstrappers.
     *
     * @param string[] $except
     * @return Contracts\TenancyBootstrapper[]
     */
    public function tenancyBootstrappers($except = []): array
    {
        return array_diff_key($this->app['config']['tenancy.bootstrappers'], array_flip($except));
    }

    public function shouldCreateDatabase(Tenant $tenant): bool
    {
        if (array_key_exists('_tenancy_create_database', $tenant->data)) {
            return $tenant->data['_tenancy_create_database'];
        }

        return $this->app['config']['tenancy.create_database'] ?? true;
    }

    public function shouldMigrateAfterCreation(): bool
    {
        return $this->app['config']['tenancy.migrate_after_creation'] ?? false;
    }

    public function shouldSeedAfterMigration(): bool
    {
        return $this->shouldMigrateAfterCreation() && $this->app['config']['tenancy.seed_after_migration'] ?? false;
    }

    public function databaseCreationQueued(): bool
    {
        return $this->app['config']['tenancy.queue_database_creation'] ?? false;
    }

    public function shouldDeleteDatabase(): bool
    {
        return $this->app['config']['tenancy.delete_database_after_tenant_deletion'] ?? false;
    }

    public function getSeederParameters()
    {
        return $this->app['config']['tenancy.seeder_parameters'] ?? [];
    }

    public function getMigrationParameters()
    {
        return $this->app['config']['tenancy.migration_parameters'] ?? [];
    }

    /**
     * Add an event listener.
     *
     * @param string $name
     * @param callable $listener
     * @return self
     */
    public function eventListener(string $name, callable $listener): self
    {
        $this->eventListeners[$name] = $this->eventListeners[$name] ?? [];
        $this->eventListeners[$name][] = $listener;

        return $this;
    }

    /**
     * Add an event hook.
     * @alias eventListener
     *
     * @param string $name
     * @param callable $listener
     * @return self
     */
    public function hook(string $name, callable $listener): self
    {
        return $this->eventListener($name, $listener);
    }

    /**
     * Trigger an event and execute its listeners.
     *
     * @param string $name
     * @param mixed ...$args
     * @return string[]
     */
    public function event(string $name, ...$args): array
    {
        return array_reduce($this->eventListeners[$name] ?? [], function ($results, $listener) use ($args) {
            return array_merge($results, $listener($this, ...$args) ?? []);
        }, []);
    }

    public function __call($method, $parameters)
    {
        if (Str::startsWith($method, 'findBy')) {
            return $this->findBy(Str::snake(substr($method, 6)), $parameters[0]);
        }

        static::throwBadMethodCallException($method);
    }
}
