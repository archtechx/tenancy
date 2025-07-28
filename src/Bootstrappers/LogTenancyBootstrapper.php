<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Closure;
use Illuminate\Log\LogManager;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * This bootstrapper allows modifying the logs so that they're tenant-specific.
 *
 * If the used channel is 'single' or 'daily', it will set the log path to
 * storage_path('logs/laravel.log') for the tenant (so the tenant log will be located at storage/tenantX/logs/laravel.log).
 * For this to work correctly, the bootstrapper needs to run after FilesystemTenancyBootstrapper.
 *
 * Channels that don't use the storage path (e.g. 'slack') will be modified as specified in the $channelOverrides property.
 *
 * You can also completely override configuration of specific channels by specifying a closure in the $channelOverrides property.
 */
class LogTenancyBootstrapper implements TenancyBootstrapper
{
    protected array $defaultConfig = [];

    // The channels that were modified (set during bootstrap so that they can be reverted later)
    protected array $channels = [];

    public static array $storagePathChannels = ['single', 'daily'];

    // E.g. 'slack' => ['url' => 'webhookUrl']
    // or 'slack' => function ($config, $tenant) { ... }
    public static array $channelOverrides = [];

    public function __construct(
        protected Config $config,
        protected LogManager $logManager,
    ) {}

    public function bootstrap(Tenant $tenant): void
    {
        $this->defaultConfig = $this->config->get('logging.channels');
        $this->channels = $this->getChannels();

        $this->configureChannels($this->channels, $tenant);
        $this->forgetChannels();
    }

    public function revert(): void
    {
        $this->config->set('logging.channels', $this->defaultConfig);

        $this->forgetChannels();

        $this->channels = [];
    }

    protected function getChannels(): array {
        $channels = [$this->config->get('logging.default')];

        // If the default channel is stack, also get all the channels it contains
        if ($channels[0] === 'stack') {
            $channels = array_merge($channels, $this->config->get('logging.channels.stack.channels'));
        }

        return $channels;
    }

    protected function configureChannels(array $channels, Tenant $tenant): void {
        foreach ($channels as $channel) {
            if (in_array($channel, array_keys(static::$channelOverrides))) {
                // Override specified channel's config as specified in the $channelOverrides property
                // Takes precedence over the storage path channels handling
                // The override is an array, use tenant property for overriding the channel config (the default approach)
                if (is_array(static::$channelOverrides[$channel])) {
                    foreach (static::$channelOverrides[$channel] as $channelConfigKey => $tenantProperty) {
                        // E.g. set 'slack' channel's 'url' to $tenant->webhookUrl
                        $this->config->set("logging.channels.{$channel}.{$channelConfigKey}", $tenant->$tenantProperty);
                    }
                }

                // If the override is a closure, call it with the config and tenant
                // This allows for more custom configurations
                if (static::$channelOverrides[$channel] instanceof Closure) {
                    static::$channelOverrides[$channel]($this->config, $tenant);
                }
            } else if (in_array($channel, static::$storagePathChannels)) {
                // Default handling for storage path channels ('single', 'daily')
                // Can be overriden by the $channelOverrides property
                // Set the log path to storage_path('logs/laravel.log') for the tenant
                // The tenant log will be located at e.g. "storage/tenant{$tenantKey}/logs/laravel.log"
                $this->config->set("logging.channels.{$channel}.path", storage_path("logs/laravel.log"));
            }
        }
    }

    protected function forgetChannels(): void {
        // Forget the channels so that they can be re-resolved with the new config on the next log attempt
        foreach ($this->channels as $channel) {
            $this->logManager->forgetChannel($channel);
        }
    }
}
