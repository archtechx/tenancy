<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Illuminate\Config\Repository;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\TenancyMailManager;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;

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
    public static array $credentialsMap = [];

    public static function smtpCredentialsMap(): array
    {
        return [
            'mail.mailers.smtp.host' => 'smtp_host',
            'mail.mailers.smtp.port' => 'smtp_port',
            'mail.mailers.smtp.username' => 'smtp_username',
            'mail.mailers.smtp.password' => 'smtp_password',
        ];
    }

    public function __construct(protected Repository $config)
    {
        $mapPreset = match($config->get('mail.default')) {
            'smtp' => static::smtpCredentialsMap(),
            default => null,
        };

        static::$credentialsMap = array_merge(static::$credentialsMap, $mapPreset ?? []);
    }

    public function bootstrap(Tenant $tenant): void
    {
        $this->setConfig($tenant);
    }

    public function revert(): void
    {
        $this->unsetConfig();
    }

    protected function setConfig(Tenant $tenant)
    {
        foreach (static::$credentialsMap as $configKey => $storageKey) {
            $override = $tenant->$storageKey;

            if (array_key_exists($storageKey, $tenant->getAttributes())) {
                $this->originalConfig[$configKey] ??= $this->config->get($configKey);

                $this->config->set($configKey, $override);
            }
        }
    }

    protected function unsetConfig()
    {
        foreach ($this->originalConfig as $key => $value) {
            $this->config->set($key, $value);
        }
    }
}
