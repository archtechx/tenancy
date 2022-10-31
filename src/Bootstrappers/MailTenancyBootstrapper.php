<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Illuminate\Config\Repository;
use Illuminate\Support\Arr;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

class MailTenancyBootstrapper implements TenancyBootstrapper
{
    protected array $originalConfig = [];

    public function __construct(protected Repository $config)
    {
    }

    public function bootstrap(Tenant $tenant): void
    {
        foreach ($this->credentialsMap() as $storageKey => $configKey) {
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

    /**
     * Tenant properties to be mapped to config (similarly the TenantConfig feature).
     *
     * For example:
     * [
     *     'property' => 'config.key.name',
     * ]
     */
    protected function credentialsMap()
    {
        return [
            'smtp_transport' => 'mail.mailers.smtp.transport',
            'smtp_host' => 'mail.mailers.smtp.host',
            'smtp_port' => 'mail.mailers.smtp.port',
            'smtp_encryption' => 'mail.mailers.smtp.encryption',
            'smtp_username' => 'mail.mailers.smtp.username',
            'smtp_password' => 'mail.mailers.smtp.password',
            'smtp_timeout' => 'mail.mailers.smtp.timeout',
            'smtp_local_domain' => 'mail.mailers.smtp.local_domain',
        ];
    }
}
