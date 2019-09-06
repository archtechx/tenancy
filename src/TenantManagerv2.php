<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Illuminate\Foundation\Application;

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
        foreach ($this->tenancyBootstrappers() as $bootstrapper) {
            $this->app[$bootstrapper]->start($tenant);
        }

        return $this;
    }

    public function endTenancy(): self
    {
        foreach ($this->tenancyBootstrappers() as $bootstrapper) {
            $this->app[$bootstrapper]->end();
        }

        return $this;
    }

    public function setTenant(Tenant $tenant): self
    {
        $this->app->instance(Tenant::class, $tenant);

        return $this;
    }

    /**
     * Return a list of TenancyBoostrappers.
     *
     * @return Contracts\TenancyBootstrapper[]
     */
    public function tenancyBootstrappers(): array
    {
        return config('tenancy.bootstrappers');
    }
}
