<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Illuminate\Foundation\Application;

// todo rethink integration events
// todo events

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

        return $this;
    }

    public function endTenancy(): self
    {
        $prevented = $this->event('ending');

        foreach ($this->tenancyBootstrappers($prevented) as $bootstrapper) {
            $this->app[$bootstrapper]->end();
        }

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
        return array_key_diff(config('tenancy.tenancy_bootstrappers'), $except);
    }

    // todo event "listeners" instead of "callbacks"

    /**
     * TODO
     *
     * @param string $name
     * @param callable $callback
     * @return self|string[]
     */
    public function event(string $name, callable $callback = null)
    {
        if ($callback) {
            return $this->addEventCallback($name, $callback);
        }

        return $this->executeEventCallbacks($name);
    }

    public function addEventCallback(string $name, callable $callback): self
    {
        isset($this->eventCallbacks[$name]) || $this->eventCallbacks[$name] = [];
        $this->eventCallbacks[$name][] = $callback;

        return $this;
    }

    /**
     * TODO
     *
     * @param string $name
     * @return string[]
     */
    public function executeEventCallbacks(string $name): array
    {
        return array_reduce($this->eventCalbacks[$name] ?? [], function ($prevented, $callback) {
            $prevented = array_merge($prevented, $callback($this) ?? []);
            
            return $prevented;
        }, []);
    }
}
