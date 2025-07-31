<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Log\LogManager;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * This bootstrapper makes it possible to configure tenant-specific logging.
 *
 * By default, the storage path channels ('single' and 'daily' by default,
 * but feel free to customize that using the $storagePathChannels property)
 * are configured to use tenant storage directories.
 * For this to work correctly, this bootstrapper must run *after* FilesystemTenancyBootstrapper.
 *
 * The bootstrapper also supports custom channel overrides via the $channelOverrides property (see the property's docblock).
 *
 * @see Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper
 */
class LogTenancyBootstrapper implements TenancyBootstrapper
{
    protected array $defaultConfig = [];

    /**
     * Log channels that use the storage_path() helper for storing the logs.
     * Requires FilesystemTenancyBootstrapper to run before this bootstrapper.
     */
    public static array $storagePathChannels = ['single', 'daily'];

    /**
     * Custom channel configuration overrides.
     *
     * Examples:
     * - Array mapping (the default approach): ['slack' => ['url' => 'webhookUrl']] maps $tenant->webhookUrl to slack.url (if $tenant->webhookUrl is set, otherwise, the override is ignored)
     * - Closure: ['slack' => fn ($config, $tenant) => $config->set('logging.channels.slack.url', $tenant->slackUrl)]
     */
    public static array $channelOverrides = [];

    public function __construct(
        protected Config $config,
        protected LogManager $logManager,
    ) {}

    public function bootstrap(Tenant $tenant): void
    {
        $this->defaultConfig = $this->config->get('logging.channels');
        $channels = $this->getChannels();

        $this->configureChannels($channels, $tenant);
        $this->forgetChannels($channels);
    }

    public function revert(): void
    {
        $this->config->set('logging.channels', $this->defaultConfig);

        $this->forgetChannels($this->getChannels());
    }

    /**
     * Channels to configure and re-resolve afterwards (including the channels in the log stack).
     */
    protected function getChannels(): array
    {
        // Get the currently used (default) logging channel
        $defaultChannel = $this->config->get('logging.default');
        $channelIsStack = $this->config->get("logging.channels.{$defaultChannel}.driver") === 'stack';

        // If the default channel is stack, also get all the channels it contains.
        // The stack channel also has to be included in the list of channels
        // since the channel will be resolved and saved in the log manager,
        // and its config could accidentally be used instead of the underlying channels.
        //
        // For example, when you use 'stack' with the 'slack' channel and you want to configure the webhook URL,
        // both the 'stack' and the 'slack' must be re-resolved after updating the config for the channels to use the correct webhook URLs.
        // If only one of the mentioned channels would be re-resolved, the other's webhook URL would be used for logging.
        $channels = $channelIsStack
            ? [$defaultChannel, ...$this->config->get("logging.channels.{$defaultChannel}.channels")]
            : [$defaultChannel];

        return $channels;
    }

    /**
     * Configure channels for the tenant context.
     *
     * Only the channels that are in the $storagePathChannels array
     * or have custom overrides in the $channelOverrides property
     * will be configured.
     */
    protected function configureChannels(array $channels, Tenant $tenant): void
    {
        foreach ($channels as $channel) {
            if (isset(static::$channelOverrides[$channel])) {
                $this->overrideChannelConfig($channel, static::$channelOverrides[$channel], $tenant);
            } elseif (in_array($channel, static::$storagePathChannels)) {
                // Set storage path channels to use tenant-specific directory (default behavior)
                // The tenant log will be located at e.g. "storage/tenant{$tenantKey}/logs/laravel.log"
                $this->config->set("logging.channels.{$channel}.path", storage_path('logs/laravel.log'));
            }
        }
    }

    protected function overrideChannelConfig(string $channel, array|Closure $override, Tenant $tenant): void
    {
        if (is_array($override)) {
            // Map tenant properties to channel config keys.
            // If the tenant property is not set (= is null),
            // the override is ignored and the channel config key's value remains unchanged.
            foreach ($override as $configKey => $tenantAttributeName) {
                $tenantAttribute = $tenant->getAttribute($tenantAttributeName);

                if ($tenantAttribute !== null) {
                    $this->config->set("logging.channels.{$channel}.{$configKey}", $tenantAttribute);
                }
            }
        } elseif ($override instanceof Closure) {
            $override($this->config, $tenant);
        }
    }

    /**
     * Forget all passed channels so they can be re-resolved
     * with updated config on the next logging attempt.
     */
    protected function forgetChannels(array $channels): void
    {
        foreach ($channels as $channel) {
            $this->logManager->forgetChannel($channel);
        }
    }
}
