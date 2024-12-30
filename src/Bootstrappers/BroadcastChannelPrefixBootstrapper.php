<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Closure;
use Illuminate\Broadcasting\Broadcasters\AblyBroadcaster;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Foundation\Application;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Overrides broadcasters (by default, using $broadcasterManager->extend())
 * so that the channel names they actually use to broadcast events get prefixed.
 *
 * Channels you return in the broadcastOn() methods of the events are passed to the formatChannels() method.
 * Broadcasters use that method to format the names of the channels on which the event will broadcast,
 * so we override it to prefix the final channel names the broadcasters use for event broadcasting.
 */
class BroadcastChannelPrefixBootstrapper implements TenancyBootstrapper
{
    /**
     * Closures overriding broadcasters with custom broadcasters that prefix the channel names with the tenant keys.
     *
     * The key is the broadcaster's name, and the value is a closure that should prefix the broadcaster's channels.
     * $broadcasterOverrides['custom'] = fn () => ...; // Custom override closure
     *
     * For more info, see the default override methods in this class (pusher() and ably()).
     */
    public static array $broadcasterOverrides = [];

    protected array $originalBroadcasters = [];

    public function __construct(
        protected Application $app,
        protected BroadcastManager $broadcastManager,
    ) {}

    public function bootstrap(Tenant $tenant): void
    {
        foreach (static::$broadcasterOverrides as $broadcaster => $broadcasterOverride) {
            // Save the original broadcaster, so that we can revert to it later
            $this->originalBroadcasters[$broadcaster] = $this->broadcastManager->driver($broadcaster);

            // Delete the cached broadcaster, so that the manager uses the new one
            $this->broadcastManager->purge($broadcaster);

            $broadcasterOverride($this->broadcastManager);

            // Get the overridden broadcaster
            $newBroadcaster = $this->broadcastManager->driver($broadcaster);

            // Register the original broadcaster's channels in the new broadcaster
            foreach ($this->originalBroadcasters[$broadcaster]->getChannels() as $channel => $callback) {
                $newBroadcaster->channel($channel, $callback);
            }
        }
    }

    public function revert(): void
    {
        // Revert to the original broadcasters
        foreach ($this->originalBroadcasters as $name => $broadcaster) {
            // Delete the cached (overridden) broadcaster
            $this->broadcastManager->purge($name);

            // Make manager return the original broadcaster instance
            // Whenever the broadcaster is requested
            $this->broadcastManager->extend($name, fn ($app, $config) => $broadcaster);
        }
    }

    /**
     * Set the closure that overrides the 'pusher' broadcaster.
     *
     * By default, override the 'pusher' broadcaster with a broadcaster that
     * extends PusherBroadcaster, and overrides the formatChannels() method,
     * such that e.g. 'private-channel' becomes 'private-tenantKey.channel'.
     */
    public static function pusher(Closure|null $override = null, string $driver = 'pusher'): void
    {
        static::$broadcasterOverrides[$driver] = $override ?? function (BroadcastManager $broadcastManager) use ($driver): void {
            $broadcastManager->extend($driver, function ($app, $config) use ($broadcastManager) {
                return new class($broadcastManager->pusher($config)) extends PusherBroadcaster {
                    protected function formatChannels(array $channels)
                    {
                        $formatChannel = function (string $channel) {
                            $prefixes = ['private-', 'presence-', 'private-encrypted-'];
                            $defaultPrefix = '';

                            foreach ($prefixes as $prefix) {
                                if (str($channel)->startsWith($prefix)) {
                                    $defaultPrefix = $prefix;
                                    break;
                                }
                            }

                            // Give the tenant prefix to channels that aren't flagged as global
                            if (! str($channel)->startsWith('global__')) {
                                $channel = str($channel)->after($defaultPrefix)->prepend($defaultPrefix . tenant()->getTenantKey() . '.');
                            }

                            return (string) $channel;
                        };

                        return array_map($formatChannel, $channels);
                    }
                };
            });
        };
    }

    /**
     * Set the closure that overrides the 'reverb' broadcaster.
     *
     * By default, override the 'reverb' broadcaster with a broadcaster that
     * extends PusherBroadcaster, and overrides the formatChannels() method,
     * such that e.g. 'private-channel' becomes 'private-tenantKey.channel'.
     */
    public static function reverb(Closure|null $override = null): void
    {
        // Reverb reuses Pusher classes, but changes the name to 'reverb'
        static::pusher($override, driver: 'reverb');
    }

    /**
     * Set the closure that overrides the 'ably' broadcaster.
     *
     * By default, override the 'ably' broadcaster with a broadcaster that
     * Extends AblyBroadcaster, and overrides the formatChannels() method
     * such that e.g. 'private-channel' becomes 'private:tenantKey.channel'.
     */
    public static function ably(Closure|null $override = null): void
    {
        static::$broadcasterOverrides['ably'] = $override ?? function (BroadcastManager $broadcastManager): void {
            $broadcastManager->extend('ably', function ($app, $config) use ($broadcastManager) {
                return new class($broadcastManager->ably($config)) extends AblyBroadcaster {
                    protected function formatChannels(array $channels)
                    {
                        $formatChannel = function (string $channel) {
                            $prefixes = ['private:', 'presence:'];
                            $defaultPrefix = '';

                            foreach ($prefixes as $prefix) {
                                if (str($channel)->startsWith($prefix)) {
                                    $defaultPrefix = $prefix;
                                    break;
                                }
                            }

                            // Give the tenant prefix to channels that aren't flagged as global
                            if (! str($channel)->startsWith('global__')) {
                                $channel = str($channel)->after($defaultPrefix)->prepend($defaultPrefix . tenant()->getTenantKey() . '.');
                            }

                            return (string) $channel;
                        };

                        return array_map($formatChannel, parent::formatChannels($channels));
                    }
                };
            });
        };
    }
}
