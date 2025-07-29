<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Log\LogManager;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Bootstrapper for tenant-specific logging configuration.
 *
 * Automatically configures storage path channels (single, daily) to use tenant storage directories.
 * Supports custom channel overrides via the $channelOverrides property.
 *
 * Note: Must run after FilesystemTenancyBootstrapper for storage path channels to work correctly.
 *
 * @see Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper
 */
class LogTenancyBootstrapper implements TenancyBootstrapper
{
    protected array $defaultConfig = [];

    /** Channels that were modified during bootstrap (for reverting later) */
    protected array $channels = [];

    /**
     * Log channels that use storage paths for storing the logs.
     * Requires FilesystemTenancyBootstrapper to run first.
     */
    public static array $storagePathChannels = ['single', 'daily'];

    /**
     * Custom channel configuration overrides.
     *
     * Examples:
     * - Array mapping: ['slack' => ['url' => 'webhookUrl']] maps $tenant->webhookUrl to slack.url
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

    /** Get all channels that need to be configured, including channels in the log stack. */
    protected function getChannels(): array
    {
        $channels = [$this->config->get('logging.default')];

        // If the default channel is stack, also get all the channels it contains
        if ($channels[0] === 'stack') {
            $channels = array_merge($channels, $this->config->get('logging.channels.stack.channels'));
        }

        return $channels;
    }

    /** Configure channels for the tenant context. */
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

    /**
     * Apply channel override configuration.
     */
    protected function overrideChannelConfig(string $channel, array|Closure $override, Tenant $tenant): void
    {
        if (is_array($override)) {
            // Map tenant properties to channel config keys
            foreach ($override as $configKey => $tenantProperty) {
                $this->config->set("logging.channels.{$channel}.{$configKey}", $tenant->$tenantProperty);
            }
        } elseif ($override instanceof Closure) {
            // Execute custom configuration closure
            $override($this->config, $tenant);
        }
    }

    /**
     * Forget channels so they can be re-resolved
     * with updated configuration on the next log attempt.
     */
    protected function forgetChannels(): void
    {
        foreach ($this->channels as $channel) {
            $this->logManager->forgetChannel($channel);
        }
    }
}
