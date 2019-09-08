<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Illuminate\Foundation\Application;
use Illuminate\Contracts\Console\Kernel as Artisan;
use Stancl\Tenancy\Contracts\TenantCannotBeCreatedException;
use Stancl\Tenancy\Exceptions\NoTenantIdentifiedException;
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

    /** @var Contracts\StorageDriver */
    protected $storage;

    // todo event "listeners" instead of "callbacks"
    /** @var callable[][] */
    protected $callbacks = [];

    public function __construct(Application $app, Contracts\StorageDriver $storage, Artisan $artisan)
    {
        $this->app = $app;
        $this->storage = $storage;

        $this->bootstrapFeatures();
    }

    public function createTenant(Tenant $tenant): self
    {
        $this->ensureTenantCanBeCreated($tenant);

        $this->storage->createTenant($tenant);
        $this->database->create($tenant);
        
        if ($this->migrateAfterCreation()) {
            $this->artisan->call('tenants:migrate', [
                '--tenants' => [$tenant['id']],
            ]);
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
     * @return Tenant
     * @throws NoTenantIdentifiedException
     */
    public function getTenant(): Tenant
    {
        if (! $this->tenant) {
            throw new NoTenantIdentifiedException;
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

    public function migrateAfterCreation(): bool
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
