<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Log\LogManager;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Enable tenant-specific logging.
 *
 * Channels included in the $storagePathChannels property will be configured
 * to write logs into the tenant's storage directory. The list includes
 * Laravel's 'single' and 'daily' channels by default. To customize it,
 * see the property's docblock.
 *
 * For the storage path channels to be scoped correctly:
 * - this bootstrapper must run *after* FilesystemTenancyBootstrapper,
 *   since FilesystemTenancyBootstrapper adjusts storage_path() for the tenant
 * - storage path suffixing has to be enabled (= config('tenancy.filesystem.suffix_storage_path')
 *   must be true), since the storage path suffix is what separates filesystem-based logs
 *
 * For logging channels that are not filesystem-based, see the $channelOverrides logic.
 *
 * @see Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper
 */
class LogTenancyBootstrapper implements TenancyBootstrapper
{
    protected array $defaultConfig = [];

    protected array $configuredChannels = [];

    /**
     * Logging channels whose path is built using storage_path() (e.g. Laravel's 'single' and 'daily').
     *
     * Channels included here will be configured to use tenant-specific storage paths
     * created using storage_path() in the tenant context. Overrides in the $channelOverrides
     * property take precedence over $storagePathChannels when a channel is included in both.
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
     * Channels included here will be configured using the provided override.
     * The overrides take precedence over the $storagePathChannels behavior
     * when both approaches are used for the same channel.
     *
     * You can either map tenant attributes to channel config keys using an array,
     * or provide a closure that returns the full channel config array.
     *
     * Examples:
     * - Array mapping: ['slack' => ['url' => 'webhookUrl']]
     *      - this maps $tenant->webhookUrl to slack.url (if $tenant->webhookUrl is null, the override is ignored)
     * - Closure: ['slack' => fn (Tenant $tenant, array $channel) => array_merge($channel, ['url' => $tenant->slackUrl])]
     *      - this manually merges ['url' => $tenant->slackUrl] into the channel's config
     *      - null is not ignored, the closure controls the override fully
     *
     * So the channel overrides can be arrays and closures that return arrays.
     */
    public static array $channelOverrides = [];

    public function __construct(
        protected Repository $config,
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
            // could be left in a corrupt state, so we revert to the original config to
            // to avoid logging the exception in a tenant channel or a broken channel.
            $this->revert();

            // We re-throw the exception after having reverted the logging config to central.
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
     * - all channels in the $storagePathChannels array
     * - all channels that have custom overrides in the $channelOverrides property
     * - any 'stack' channel that includes one of the above as a member
     *
     * Stack channels are included because once a stack has been used, it keeps logging
     * to wherever its members pointed to at that moment. So a stack used in the central
     * context would keep writing to the central logs, even after tenancy is initialized
     * and its member channels are configured for the tenant.
     * Forgetting the stack forces it to be re-resolved with its members' updated (tenant)
     * config.
     *
     * Importantly, stacks are only inspected one level deep - they are not traversed recursively.
     */
    protected function getChannels(): array
    {
        $configuredChannels = array_unique([
            ...static::$storagePathChannels,
            ...array_keys(static::$channelOverrides),
        ]);

        $stackChannels = [];

        foreach ($this->config->get('logging.channels') as $channel => $config) {
            // Include stack channels that have at least one configured channel as a member
            if (($config['driver'] ?? null) === 'stack' && array_intersect($config['channels'] ?? [], $configuredChannels)) {
                $stackChannels[] = $channel;
            }
        }

        return array_filter(
            array_unique([...$configuredChannels, ...$stackChannels]),
            fn (string $channel): bool => $this->config->has("logging.channels.{$channel}")
        );
    }

    /**
     * Configure channels for the tenant context.
     *
     * This handles both $storagePathChannels and $channelOverrides.
     */
    protected function configureChannels(array $channels, Tenant $tenant): void
    {
        foreach ($channels as $channel) {
            if (isset(static::$channelOverrides[$channel])) {
                $this->overrideChannelConfig($channel, static::$channelOverrides[$channel], $tenant);
            } elseif (in_array($channel, static::$storagePathChannels)) {
                // Set storage path channels to use a tenant-specific directory.
                // The tenant log will be located at e.g. "storage/tenant{$tenantKey}/logs/laravel.log".
                $originalChannelPath = $this->config->get("logging.channels.{$channel}.path");
                $centralStoragePath = FilesystemTenancyBootstrapper::getBoundCentralStoragePath();

                // The tenant log will inherit the segment that follows the storage path from the central channel path config.
                // For example, if a channel's path is configured to storage_path('logs/foo.log') (storage/logs/foo.log),
                // the 'logs/foo.log' segment will be passed to storage_path() in the tenant context (storage/tenant123/logs/foo.log).
                $this->config->set("logging.channels.{$channel}.path", storage_path(Str::after($originalChannelPath, $centralStoragePath)));
            }
        }
    }

    /**
     * Update channel configurations per $channelOverrides.
     *
     * For overrides set in array format, update individual keys of the channel.
     *   - This ignores cases where the value of the respective tenant attribute is null.
     * For overrides set as closures, replace the entire channel with the returned config override.
     *   - This does not ignore cases where parts of the config may be null - the closure fully controls the override.
     */
    protected function overrideChannelConfig(string $channel, array|Closure $override, Tenant $tenant): void
    {
        if (is_array($override)) {
            // Map tenant attributes to channel config keys.
            foreach ($override as $configKey => $tenantAttributeName) {
                /** @var Tenant&Model $tenant */
                $tenantAttribute = data_get($tenant, $tenantAttributeName);

                // If the tenant attribute is null, the override is ignored
                // and the channel config key's value remains unchanged.
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
     * Forget all passed channels from the log manager so that they can be
     * re-resolved with the updated config on the next logging attempt.
     */
    protected function forgetChannels(array $channels): void
    {
        foreach ($channels as $channel) {
            $this->logManager->forgetChannel($channel);
        }
    }
}
