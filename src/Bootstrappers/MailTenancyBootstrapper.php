<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Illuminate\Config\Repository;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

class MailTenancyBootstrapper implements TenancyBootstrapper
{
    protected array $originalConfig = [];

    /**
     * Tenant properties to be mapped to config (similarly to the TenantConfig feature).
     *
     * For example:
     * [
     *     'config.key.name' => 'tenant_property',
     * ]
     */
    public static array $credentialsMap = [
        'mail.mailers.smtp.transport' => 'smtp_transport',
        'mail.mailers.smtp.host' => 'smtp_host',
        'mail.mailers.smtp.port' => 'smtp_port',
        'mail.mailers.smtp.encryption' => 'smtp_encryption',
        'mail.mailers.smtp.username' => 'smtp_username',
        'mail.mailers.smtp.password' => 'smtp_password',
        'mail.mailers.smtp.timeout' => 'smtp_timeout',
        'mail.mailers.smtp.local_domain' => 'smtp_local_domain',
    ];

    public function __construct(protected Repository $config)
    {
    }

    public function bootstrap(Tenant $tenant): void
    {
        foreach (static::$credentialsMap as $configKey => $storageKey) {
            $override = $tenant->$storageKey;

            if (array_key_exists($storageKey, $tenant->getAttributes())) {
                $this->originalConfig[$configKey] ??= $this->config->get($configKey);

                $this->config->set($configKey, $override);
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
