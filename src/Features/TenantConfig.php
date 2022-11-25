<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Features;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Contracts\Feature;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Events\RevertedToCentralContext;
use Stancl\Tenancy\Events\TenancyBootstrapped;

class TenantConfig implements Feature
{
    public array $originalConfig = [];

    /** @var array<string, string|array> */
    public static array $storageToConfigMap = [
        // 'paypal_api_key' => 'services.paypal.api_key',
    ];

    public function __construct(
        protected Repository $config,
    ) {
    }

    public function bootstrap(): void
    {
        Event::listen(TenancyBootstrapped::class, function (TenancyBootstrapped $event) {
            /** @var Tenant $tenant */
            $tenant = $event->tenancy->tenant;

            $this->setTenantConfig($tenant);
        });

        Event::listen(RevertedToCentralContext::class, function () {
            $this->unsetTenantConfig();
        });
    }

    public function setTenantConfig(Tenant $tenant): void
    {
        foreach (static::$storageToConfigMap as $storageKey => $configKey) {
            /** @var Tenant&Model $tenant */
            $override = Arr::get($tenant, $storageKey);

            if (! is_null($override)) {
                if (is_array($configKey)) {
                    foreach ($configKey as $key) {
                        $this->originalConfig[$key] = $this->originalConfig[$key] ?? $this->config->get($key);

                        $this->config->set($key, $override);
                    }
                } else {
                    $this->originalConfig[$configKey] = $this->originalConfig[$configKey] ?? $this->config->get($configKey);

                    $this->config->set($configKey, $override);
                }
            }
        }
    }

    public function unsetTenantConfig(): void
    {
        foreach ($this->originalConfig as $key => $value) {
            $this->config->set($key, $value);
        }
    }
}
