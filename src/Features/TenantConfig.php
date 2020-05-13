<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Features;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Contracts\Feature;
use Stancl\Tenancy\Events\RevertedToCentralContext;
use Stancl\Tenancy\Events\TenancyBootstrapped;
use Stancl\Tenancy\Tenancy;
use Stancl\Tenancy\Contracts\Tenant;

class TenantConfig implements Feature
{
    /** @var Repository */
    protected $config;

    /** @var array */
    public $originalConfig = [];

    public static $storageToConfigMap = [
        // 'paypal_api_key' => 'services.paypal.api_key',
    ];

    public function __construct(Repository $config)
    {
        $this->config = $config;

        foreach (static::$storageToConfigMap as $configKey) {
            $this->originalConfig[$configKey] = $this->config[$configKey];
        }
    }

    public function bootstrap(Tenancy $tenancy): void
    {
        Event::listen(TenancyBootstrapped::class, function (TenancyBootstrapped $event) {
            $this->setTenantConfig($event->tenancy->tenant);
        });

        Event::listen(RevertedToCentralContext::class, function () {
            $this->unsetTenantConfig();
        });
    }

    public function setTenantConfig(Tenant $tenant): void
    {
        foreach (static::$storageToConfigMap as $storageKey => $configKey) {
            $override = $tenant->$storageKey ?? null;
            if (! is_null($override)) {
                $this->config[$configKey] = $override;
            }
        }
    }

    public function unsetTenantConfig(): void
    {
        foreach (static::$storageToConfigMap as $configKey) {
            $this->config[$configKey] = $this->originalConfig[$configKey];
        }
    }
}
