<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Features;

use Illuminate\Foundation\Application;
use Stancl\Tenancy\Contracts\Feature;
use Stancl\Tenancy\Tenant;
use Stancl\Tenancy\TenantManager;

class TenantConfig implements Feature
{
    /** @var Application */
    protected $app;

    /** @var array */
    public $originalConfig = [];

    public function __construct(Application $app)
    {
        $this->app = $app;

        foreach ($this->getStorageToConfigMap() as $configKey) {
            $this->originalConfig[$configKey] = $this->app['config'][$configKey];
        }
    }

    public function bootstrap(TenantManager $tenantManager): void
    {
        $tenantManager->eventListener('bootstrapped', function (TenantManager $manager) {
            $this->setTenantConfig($manager->getTenant());
        });

        $tenantManager->eventListener('ended', function () {
            $this->unsetTenantConfig();
        });
    }

    public function setTenantConfig(Tenant $tenant): void
    {
        foreach ($this->getStorageToConfigMap() as $storageKey => $configKey) {
            $this->app['config'][$configKey] = $tenant->get($storageKey);
        }
    }

    public function unsetTenantConfig(): void
    {
        foreach ($this->getStorageToConfigMap() as $configKey) {
            $this->app['config'][$configKey] = $this->originalConfig[$configKey];
        }
    }

    public function getStorageToConfigMap(): array
    {
        return $this->app['config']['tenancy.storage_to_config_map'] ?? [];
    }
}
