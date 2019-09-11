<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Illuminate\Foundation\Application;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Stancl\Tenancy\Exceptions\NoTenantIdentifiedException;
use Stancl\Tenancy\Contracts\TenantCannotBeCreatedException;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedException;

/**
 * @internal Class is subject to breaking changes in minor and patch versions.
 */
class TenantManagerv2
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
    protected $storage;

    /** @var DatabaseManager */
    protected $database;

    // todo event "listeners" instead of "callbacks"
    /** @var callable[][] */
    protected $callbacks = [];

    public function __construct(Application $app, ConsoleKernel $artisan, Contracts\StorageDriver $storage, DatabaseManager $database)
    {
        $this->app = $app;
        $this->storage = $storage;
        $this->artisan = $artisan;
        $this->database = $database;

        $this->bootstrapFeatures();
    }

    public function createTenant(Tenant $tenant): self
    {
        $this->ensureTenantCanBeCreated($tenant);

        $this->storage->createTenant($tenant);
        $this->database->createDatabase($tenant);

        if ($this->shouldMigrateAfterCreation()) {
            $this->artisan->call('tenants:migrate', [
                '--tenants' => [$tenant['id']],
            ]);
        }

        return $this;
    }

    public function deleteTenant(Tenant $tenant): self
    {
        $this->storage->deleteTenant($tenant);

        if ($this->shouldDeleteDatabase()) {
            $this->database->deleteDatabase($tenant);
        }

        return $this;
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
        // todo move the "throw" responsibility to the canCreateTenant methods?
        if (($e = $this->storage->canCreateTenant($tenant)) instanceof TenantCannotBeCreatedException) {
            throw new $e;
        }

        if (($e = $this->database->canCreateTenant($tenant)) instanceof TenantCannotBeCreatedException) {
            throw new $e;
        }
    }

    public function updateTenant(Tenant $tenant): self
    {
        $this->storage->updateTenant($tenant);

        return $this;
    }

    public function init(string $domain): self
    {
        $this->initializeTenancy($this->findByDomain($domain));

        return $this;
    }

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
     * @return Tenant[]
     */
    public function all($only = []): array
    {
        $only = array_map(function ($item) {
            return $item instanceof Tenant ? $item->id : $item;
        }, $only);

        return $this->storage->all($only);
    }

    public function initializeTenancy(Tenant $tenant): self
    {
        $this->bootstrapTenancy($tenant);
        $this->setTenant($tenant);

        return $this;
    }

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

        $this->event('ended');

        return $this;
    }

    /**
     * Get the current tenant.
     *
     * @param string $key
     * @return Tenant
     * @throws NoTenantIdentifiedException
     */
    public function getTenant(string $key = null): Tenant
    {
        if (! $this->tenant) {
            throw new NoTenantIdentifiedException;
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
     * Return a list of TenancyBoostrappers.
     *
     * @param string[] $except
     * @return Contracts\TenancyBootstrapper[]
     */
    public function tenancyBootstrappers($except = []): array
    {
        return array_key_diff($this->app['config']['tenancy.bootstrappers'], $except);
    }

    public function shouldMigrateAfterCreation(): bool
    {
        return $this->app['config']['tenancy.migrate_after_creation'] ?? false;
    }

    /**
     * Add an event callback.
     *
     * @param string $name
     * @param callable $callback
     * @return self
     */
    public function eventCallback(string $name, callable $callback): self
    {
        $this->eventCallbacks[$name] = $this->eventCallbacks[$name] ?? [];
        $this->eventCallbacks[$name][] = $callback;

        return $this;
    }

    /**
     * Execute event callbacks.
     *
     * @param string $name
     * @return string[]
     */
    protected function event(string $name): array
    {
        return array_reduce($this->eventCalbacks[$name] ?? [], function ($prevented, $callback) {
            $prevented = array_merge($prevented, $callback($this) ?? []);

            return $prevented;
        }, []);
    }
}
