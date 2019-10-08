<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Features;

use Illuminate\Contracts\Config\Repository;
use Stancl\Tenancy\Contracts\Feature;
use Stancl\Tenancy\Tenant;
use Stancl\Tenancy\TenantManager;

class TenantConfig implements Feature
{
    /** @var Repository */
    protected $config;

    /** @var array */
    public $originalConfig = [];

    public function __construct(Repository $config)
    {
        $this->config = $config;

        foreach ($this->getStorageToConfigMap() as $configKey) {
            $this->originalConfig[$configKey] = $this->config[$configKey];
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
            $override = $tenant->data[$storageKey] ?? null;
            if (! is_null($override)) {
                $this->config[$configKey] = $override;
            }
        }
    }

    public function unsetTenantConfig(): void
    {
        foreach ($this->getStorageToConfigMap() as $configKey) {
            $this->config[$configKey] = $this->originalConfig[$configKey];
        }
    }

    public function getStorageToConfigMap(): array
    {
        return $this->config['tenancy.storage_to_config_map'] ?? [];
    }
}
