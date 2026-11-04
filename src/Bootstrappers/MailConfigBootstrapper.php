<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Mail\MailManager;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

class MailConfigBootstrapper implements TenancyBootstrapper
{
    /**
     * Tenant properties to be mapped to config (similarly to the TenantConfigBootstrapper).
     *
     * For example:
     * [
     *     'config.key.username' => 'tenant_property',
     *     'config.key.password' => 'nested.tenant_property',
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
    }

    public function bootstrap(Tenant $tenant): void
    {
        $this->forgetMailers();

        $this->setConfig($tenant);
    }

    public function revert(): void
    {
        $this->unsetConfig();

        $this->forgetMailers();
    }

    /**
     * Clear the mailers cached by the MailManager so that they get resolved again using the current config.
     *
     * The mailers are cleared on the existing MailManager instead of binding a new one, because the existing
     * instance is also held outside the container -- by the Mail facade, and by the MailChannel cached in the
     * ChannelManager singleton (used for mail notifications). Keeping the instance also keeps the custom
     * transports registered using Mail::extend().
     */
    protected function forgetMailers(): void
    {
        $manager = $this->app->resolved('mail.manager') ? $this->app->make('mail.manager') : null;

        // The bound manager can also be e.g. a MailFake, which doesn't cache any transports
        if ($manager instanceof MailManager) {
            $manager->forgetMailers();
        }
    }

    protected function setConfig(Tenant $tenant): void
    {
        // Mappings specified in $credentialsMap take precedence over the preset mappings
        $credentialsMap = array_merge(
            static::$mapPresets[static::$mailer] ?? [],
            static::$credentialsMap,
        );

        foreach ($credentialsMap as $configKey => $storageKey) {
            $override = data_get($tenant, $storageKey);

            if ($override !== null) {
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
