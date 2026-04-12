<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Illuminate\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

class TenantConfigBootstrapper implements TenancyBootstrapper
{
    public array $originalConfig = [];

    /** @var array<string, string|array> */
    public static array $storageToConfigMap = [
        // 'paypal_api_key' => 'services.paypal.api_key',
    ];

    public function __construct(
        protected Repository $config,
    ) {}

    public function bootstrap(Tenant $tenant): void
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

    public function revert(): void
    {
        foreach ($this->originalConfig as $key => $value) {
            $this->config->set($key, $value);
        }
    }
}
