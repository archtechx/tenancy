<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Illuminate\Foundation\Application;

// todo rethink integration events

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
    public $tenant;

    /** @var Application */
    private $app;

    /** @var Contracts\StorageDriver */
    private $storage;

    // todo event "listeners" instead of "callbacks"
    /** @var callable[][] */
    public $callbacks = [];

    public function __construct(Application $app, Contracts\StorageDriver $storage)
    {
        $this->app = $app;
        $this->storage = $storage;
    }

    public function addTenant(Tenant $tenant): self
    {
        $this->storage->addTenant($tenant);

        return $this;
    }

    public function updateTenant(Tenant $tenant): self
    {
        $this->storage->updateTenant($tenant);

        return $this;
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

    public function setTenant(Tenant $tenant): self
    {
        $this->app->instance(Contracts\Tenant::class, $tenant);

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
        return array_key_diff(config('tenancy.bootstrappers'), $except);
    }

    /**
     * Add event callback.
     *
     * @param string $name
     * @param callable $callback
     * @return self
     */
    public function eventCallback(string $name, callable $callback): self
    {
        isset($this->eventCallbacks[$name]) || $this->eventCallbacks[$name] = [];
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
