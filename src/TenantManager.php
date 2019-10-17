<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Collection;
use Stancl\Tenancy\Contracts\TenantCannotBeCreatedException;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedException;
use Stancl\Tenancy\Jobs\QueuedTenantDatabaseMigrator;
use Stancl\Tenancy\Jobs\QueuedTenantDatabaseSeeder;

/**
 * @internal Class is subject to breaking changes in minor and patch versions.
 */
class TenantManager
{
    /**
     * The current tenant.
     *
     * @var Tenant
     */
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
        $this->database = $database;

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
        $this->ensureTenantCanBeCreated($tenant);

        $this->storage->createTenant($tenant);

        /** @var \Illuminate\Contracts\Queue\ShouldQueue[]|callable[] $afterCreating */
        $afterCreating = [];

        if ($this->shouldMigrateAfterCreation()) {
            $afterCreating[] = $this->databaseCreationQueued()
                ? new QueuedTenantDatabaseMigrator($tenant)
                : function () use ($tenant) {
                    $this->artisan->call('tenants:migrate', [
                        '--tenants' => [$tenant['id']],
                    ]);
                };
        }

        if ($this->shouldSeedAfterMigration()) {
            $afterCreating[] = $this->databaseCreationQueued()
                ? new QueuedTenantDatabaseSeeder($tenant, $this->getSeederParameters())
                : function () use ($tenant) {
                    $this->artisan->call('tenants:seed', [
                        '--tenants' => [$tenant['id']],
                    ] + $this->getSeederParameters());
                };
        }

        $this->database->createDatabase($tenant, $afterCreating);

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
        $this->storage->deleteTenant($tenant);

        if ($this->shouldDeleteDatabase()) {
            $this->database->deleteDatabase($tenant);
        }

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
        $this->storage->ensureTenantCanBeCreated($tenant);
        $this->database->ensureTenantCanBeCreated($tenant);
    }

    /**
     * Update an existing tenant in storage.
     *
     * @param Tenant $tenant
     * @return self
     */
    public function updateTenant(Tenant $tenant): self
    {
        $this->storage->updateTenant($tenant);

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
        $prevented = $this->event('bootstrapping');

        foreach ($this->tenancyBootstrappers($prevented) as $bootstrapper) {
            $this->app[$bootstrapper]->start($tenant);
        }

        $this->event('bootstrapped');

        return $this;
    }

    public function endTenancy(): self
    {
        $prevented = $this->event('ending');

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
     * Execute event listeners.
     *
     * @param string $name
     * @return string[]
     */
    protected function event(string $name): array
    {
        return array_reduce($this->eventListeners[$name] ?? [], function ($prevented, $listener) {
            $prevented = array_merge($prevented, $listener($this) ?? []);

            return $prevented;
        }, []);
    }
}
