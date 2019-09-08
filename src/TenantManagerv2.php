<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Illuminate\Foundation\Application;
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

    public function __construct(Application $app, Contracts\StorageDriver $storage)
    {
        $this->app = $app;
        $this->storage = $storage;

        $this->bootstrapFeatures();
    }

    public function createTenant(Tenant $tenant): self
    {
        // todo make this atomic
        $this->storage->createTenant($tenant);
        // todo create database, optionally migrate

        return $this;
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
