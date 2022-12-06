<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Mail\MailManager;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

class MailTenancyBootstrapper implements TenancyBootstrapper
{
    /**
     * Tenant properties to be mapped to config (similarly to the TenantConfig feature).
     *
     * For example:
     * [
     *     'config.key.name' => 'tenant_property',
     * ]
     */
    public static array $credentialsMap = [];

    public static string|null $mailer = null;

    protected array $originalConfig = [];

    public static array $mapPresets = [
        'smtp' => [
            'mail.mailers.smtp.host' => 'smtp_host',
            'mail.mailers.smtp.port' => 'smtp_port',
            'mail.mailers.smtp.username' => 'smtp_username',
            'mail.mailers.smtp.password' => 'smtp_password',
        ],
    ];

    public function __construct(
        protected Repository $config,
        protected Application $app
    ) {
        static::$mailer ??= $config->get('mail.default');
        static::$credentialsMap = array_merge(static::$credentialsMap, static::$mapPresets[static::$mailer] ?? []);
    }

    public function bootstrap(Tenant $tenant): void
    {
        // Use custom mail manager that resolves the mailers specified in its $tenantMailers static property
        // Instead of getting the cached mailers from the $mailers property
        $this->bindNewMailManagerInstance();

        $this->setConfig($tenant);
    }

    public function revert(): void
    {
        $this->unsetConfig();

        $this->bindNewMailManagerInstance();
    }

    protected function bindNewMailManagerInstance()
    {
        $this->app->extend(MailManager::class, function (MailManager $mailManager) {
            return new MailManager($this->app);
        });
    }

    protected function setConfig(Tenant $tenant): void
    {
        foreach (static::$credentialsMap as $configKey => $storageKey) {
            $override = $tenant->$storageKey;

            if (array_key_exists($storageKey, $tenant->getAttributes())) {
                $this->originalConfig[$configKey] ??= $this->config->get($configKey);

                $this->config->set($configKey, $override);
            }
        }
    }

    protected function unsetConfig(): void
    {
        foreach ($this->originalConfig as $key => $value) {
            $this->config->set($key, $value);
        }
    }
}
