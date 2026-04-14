<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Log\LogManager;
use Illuminate\Support\Arr;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;
use InvalidArgumentException;

/**
 * This bootstrapper makes it possible to configure tenant-specific logging.
 *
 * By default, the storage path channels ('single' and 'daily' by default,
 * but feel free to customize that using the $storagePathChannels property)
 * are configured to use tenant storage directories.
 * For this to work correctly, this bootstrapper must run *after* FilesystemTenancyBootstrapper.
 * FilesystemTenancyBootstrapper alters how storage_path() works in the tenant context.
 *
 * The bootstrapper also supports custom channel overrides via the $channelOverrides property (see the property's docblock).
 *
 * @see Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper
 */
class LogTenancyBootstrapper implements TenancyBootstrapper
{
    protected array $defaultConfig = [];

    protected array $configuredChannels = [];

    /**
     * Log channels that use the storage_path() helper for storing the logs. Requires FilesystemTenancyBootstrapper to run before this bootstrapper.
     * Or you can bypass this default behavior by using overrides, since they take precedence over the default behavior.
     *
     * All channels included here will be configured to use tenant-specific storage paths.
     */
    public static array $storagePathChannels = ['single', 'daily'];

    /**
     * Custom channel configuration overrides.
     *
     * All channels included here will be configured using the provided override.
     *
     * Examples:
     * - Array mapping (the default approach): ['slack' => ['url' => 'webhookUrl']] maps $tenant->webhookUrl to slack.url (if $tenant->webhookUrl is not null, otherwise, the override is ignored)
     * - Closure: ['slack' => fn (Tenant $tenant, array $channel) => array_merge($channel, ['url' => $tenant->slackUrl])] (the closure should return the whole channel's config)
     *
     * In both cases, the override should be an array.
     */
    public static array $channelOverrides = [];

    public function __construct(
        protected Config $config,
        protected LogManager $logManager,
    ) {}

    public function bootstrap(Tenant $tenant): void
    {
        $this->defaultConfig = $this->config->get('logging.channels');
        $this->configuredChannels = $this->getChannels();

        try {
            $this->configureChannels($this->configuredChannels, $tenant);
            $this->forgetChannels($this->configuredChannels);
        } catch (\Throwable $exception) {
            // Revert to default config if anything goes wrong during channel configuration
            $this->config->set('logging.channels', $this->defaultConfig);
            $this->forgetChannels($this->configuredChannels);

            throw $exception;
        }
    }

    public function revert(): void
    {
        $this->config->set('logging.channels', $this->defaultConfig);

        $this->forgetChannels($this->configuredChannels);
    }

    /**
     * Channels to configure and forget so they can be re-resolved afterwards.
     *
     * Includes:
     * - the default channel
     * - all channels in the $storagePathChannels array
     * - all channels that have custom overrides in the $channelOverrides property
     */
    protected function getChannels(): array
    {
        /**
         * Include the default channel in the list of channels to configure/re-resolve.
         *
         * Including the default channel is harmless (if it's not overridden or not in $storagePathChannels,
         * it'll just be forgotten and re-resolved on the next use), and for the case where 'stack' is the default,
         * this is necessary since the 'stack' channel will be resolved and saved in the log manager,
         * and its stale config could accidentally be used instead of the stack member channels.
         *
         * For example, when you use 'stack' with the 'slack' channel and you want to configure the webhook URL,
         * both 'stack' and 'slack' must be re-resolved after updating the config for the channels to use the correct webhook URLs.
         * If only one of the mentioned channels would be re-resolved, the other's (stale) webhook URL could be used for logging.
         */
        $defaultChannel = $this->config->get('logging.default');

        return array_filter(
            array_unique([
                $defaultChannel,
                ...static::$storagePathChannels,
                ...array_keys(static::$channelOverrides),
            ]),
            fn (string $channel): bool => $this->config->has("logging.channels.{$channel}")
        );
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
                // The tenant log will be located at e.g. "storage/tenant{$tenantKey}/logs/laravel.log" (assuming FilesystemTenancyBootstrapper is used before this bootstrapper)
                $this->config->set("logging.channels.{$channel}.path", storage_path('logs/laravel.log'));
            }
        }
    }

    protected function overrideChannelConfig(string $channel, array|Closure $override, Tenant $tenant): void
    {
        if (is_array($override)) {
            // Map tenant attributes to channel config keys.
            // If the tenant attribute is null,
            // the override is ignored and the channel config key's value remains unchanged.
            foreach ($override as $configKey => $tenantAttributeName) {
                /** @var Tenant&Model $tenant */
                $tenantAttribute = Arr::get($tenant, $tenantAttributeName);

                if ($tenantAttribute !== null) {
                    $this->config->set("logging.channels.{$channel}.{$configKey}", $tenantAttribute);
                }
            }
        } elseif ($override instanceof Closure) {
            $channelConfigKey = "logging.channels.{$channel}";

            $result = $override($tenant, $this->config->get($channelConfigKey));

            if (! is_array($result)) {
                throw new InvalidArgumentException("Channel override closure for '{$channel}' must return an array.");
            }

            $this->config->set($channelConfigKey, $result);
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
