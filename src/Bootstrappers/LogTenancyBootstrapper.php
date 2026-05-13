<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Log\LogManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Enable tenant-specific logging.
 *
 * All the storage path channels are configured to use tenant
 * directories by default (see the $storagePathChannels property).
 *
 * For this to work correctly:
 * - this bootstrapper must run *after* FilesystemTenancyBootstrapper,
 *   since FilesystemTenancyBootstrapper makes storage_path() return the tenant-specific storage path
 * - storage path suffixing has to be enabled (= config('tenancy.filesystem.suffix_storage_path')
 *   has to be true), since the storage path suffix is what separates logs by tenant
 *
 * Also supports custom channel overrides (see the $channelOverrides property).
 *
 * @see Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper
 */
class LogTenancyBootstrapper implements TenancyBootstrapper
{
    protected array $defaultConfig = [];

    protected array $configuredChannels = [];

    /**
     * Logging channels whose paths use storage_path() by default in the logging config.
     *
     * All channels included here will be configured to use tenant-specific storage paths
     * generated using storage_path() in the tenant context.
     *
     * This is the default behavior. The $channelOverrides property can be used to override
     * this behavior (the overrides take precedence over $storagePathChannels).
     *
     * Requires FilesystemTenancyBootstrapper to run before this bootstrapper,
     * and storage path suffixing to be enabled.
     *
     * @see Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper
     */
    public static array $storagePathChannels = ['single', 'daily'];

    /**
     * Custom channel configuration overrides.
     *
     * All channels included here will be configured using the provided override.
     * The overrides take precedence over the default ($storagePathChannels) behavior.
     *
     * You can either map tenant attributes to channel config keys using an array,
     * or provide a closure that returns the full channel config array.
     *
     * Examples:
     * - Array mapping (the default approach): ['slack' => ['url' => 'webhookUrl']]
     *      - this maps $tenant->webhookUrl to slack.url (if $tenant->webhookUrl is not null, otherwise, the override is ignored)
     * - Closure: ['slack' => fn (Tenant $tenant, array $channel) => array_merge($channel, ['url' => $tenant->slackUrl])]
     *      - this merges ['url' => $tenant->slackUrl] into the channel's config.
     *
     * So the channel overrides can be arrays and closures that return arrays.
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
            // If an exception is thrown while updating the logging config, the logging config
            // could be left in a corrupt (tenant) state, so we revert to the original config
            // to e.g. avoid logging the failure in the tenant log (which would happen if
            // the channel wasn't resolved before).
            $this->revert();

            throw $exception;
        }
    }

    public function revert(): void
    {
        $this->config->set('logging.channels', $this->defaultConfig);

        $this->forgetChannels($this->configuredChannels);
    }

    /**
     * Channels to configure and forget from the log manager so they can be
     * re-resolved with the new, tenant-specific config on the next use.
     *
     * Includes:
     * - the default channel (primarily because it can be 'stack')
     * - all channels in the $storagePathChannels array
     * - all channels that have custom overrides in the $channelOverrides property
     */
    protected function getChannels(): array
    {
        /**
         * Include the default channel in the list of channels to configure/re-resolve.
         *
         * Including the default channel is harmless (if it's not overridden or not in $storagePathChannels,
         * it'll just be forgotten and re-resolved on the next use with the original config), and for the
         * case where 'stack' is the default, this is necessary since the 'stack' channel will be resolved
         * and saved in the log manager, and its stale config could accidentally be used instead of the stack member channels.
         *
         * For example, when you use 'stack' with the 'slack' channel,
         * if only 'slack' is forgotten, 'stack' would still use the stale cached 'slack' driver,
         * and if only 'stack' is forgotten, the 'slack' channel's config would remain unchanged (central).
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
     * will be configured (overrides take precedence over storage path channels).
     */
    protected function configureChannels(array $channels, Tenant $tenant): void
    {
        foreach ($channels as $channel) {
            if (isset(static::$channelOverrides[$channel])) {
                $this->overrideChannelConfig($channel, static::$channelOverrides[$channel], $tenant);
            } elseif (in_array($channel, static::$storagePathChannels)) {
                // Set storage path channels to use tenant-specific directory (default behavior).
                // The tenant log will be located at e.g. "storage/tenant{$tenantKey}/logs/laravel.log",
                // assuming FilesystemTenancyBootstrapper is used before this bootstrapper.
                $originalChannelPath = $this->config->get("logging.channels.{$channel}.path");
                $centralStoragePath = Str::before(storage_path(), $this->config->get('tenancy.filesystem.suffix_base') . $tenant->getTenantKey());

                // The tenant log will inherit the segment that follows the storage path from the central channel path config.
                // For example, if a channel's path is configured to storage_path('custom/logs/path.log') (storage/custom/logs/path.log),
                // the 'custom/logs/path.log' segment will be passed to storage_path() in the tenant context (storage/tenantfoo/custom/logs/path.log).
                $this->config->set("logging.channels.{$channel}.path", storage_path(Str::after($originalChannelPath, $centralStoragePath)));
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
     * Forget all passed channels from the log manager so that
     * they can be re-resolved with the updated (tenant-specific)
     * config on the next logging attempt.
     */
    protected function forgetChannels(array $channels): void
    {
        foreach ($channels as $channel) {
            $this->logManager->forgetChannel($channel);
        }
    }
}
