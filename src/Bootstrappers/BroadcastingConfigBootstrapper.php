<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Broadcasting\Broadcaster as BroadcasterContract;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Broadcast;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Maps tenant credentials to the broadcasting config and rebinds BroadcastManager
 * and Broadcaster so that broadcasters get resolved using the tenant credentials.
 */
class BroadcastingConfigBootstrapper implements TenancyBootstrapper
{
    /**
     * Tenant properties to be mapped to config (similarly to the TenantConfigBootstrapper).
     *
     * For example:
     * [
     *     'config.key.name' => 'tenant_property',
     * ]
     *
     * $tenant->tenant_property will be mapped to config('config.key.name') when tenancy is initialized.
     */
    public static array $credentialsMap = [];

    public static string|null $broadcaster = null;

    protected array $originalConfig = [];
    protected BroadcastManager|null $originalBroadcastManager = null;
    protected BroadcasterContract|null $originalBroadcaster = null;

    public static array $mapPresets = [
        'pusher' => [
            'broadcasting.connections.pusher.key' => 'pusher_key',
            'broadcasting.connections.pusher.secret' => 'pusher_secret',
            'broadcasting.connections.pusher.app_id' => 'pusher_app_id',
            'broadcasting.connections.pusher.options.cluster' => 'pusher_cluster',
        ],
        'reverb' => [
            'broadcasting.connections.reverb.key' => 'reverb_key',
            'broadcasting.connections.reverb.secret' => 'reverb_secret',
            'broadcasting.connections.reverb.app_id' => 'reverb_app_id',
            'broadcasting.connections.reverb.options.cluster' => 'reverb_cluster',
        ],
        'ably' => [
            'broadcasting.connections.ably.key' => 'ably_key',
            'broadcasting.connections.ably.public' => 'ably_public',
        ],
    ];

    public function __construct(
        protected Repository $config,
        protected Application $app
    ) {
        static::$broadcaster ??= $config->get('broadcasting.default');
        static::$credentialsMap = array_merge(static::$mapPresets[static::$broadcaster] ?? [], static::$credentialsMap);
    }

    public function bootstrap(Tenant $tenant): void
    {
        $this->originalBroadcastManager = $this->app->make(BroadcastManager::class);
        $this->originalBroadcaster = $this->app->make(BroadcasterContract::class);

        $this->setConfig($tenant);

        // Make BroadcastManager resolve to a fresh manager with no cached broadcasters,
        // so that its broadcasters get resolved using the updated (tenant) broadcasting
        // config and stay cached for the duration of the tenant's context.
        $this->app->extend(BroadcastManager::class, function (BroadcastManager $centralManager) {
            $tenantManager = new BroadcastManager($this->app);

            // Pass the custom driver creators registered in the central context to the new manager
            // so that custom drivers work in tenant context without having to re-register the creators manually.
            foreach (invade($centralManager)->customCreators as $driver => $creator) {
                $tenantManager->extend($driver, $creator);
            }

            return $tenantManager;
        });

        // Swap the currently bound Broadcaster singleton (resolved earlier with the central credentials)
        // for the tenant BroadcastManager's default broadcaster, so that anything resolving the Broadcaster
        // contract gets the same tenant broadcaster that the manager uses, instead of the stale central one.
        $this->app->extend(BroadcasterContract::class, function (BroadcasterContract $centralBroadcaster) {
            $tenantBroadcaster = $this->app->make(BroadcastManager::class)->connection();

            // The newly resolved broadcaster doesn't have any channel auth closures registered, so the
            // closures registered in central context (e.g. in routes/channels.php) have to be passed to it
            // manually, otherwise, Broadcast::auth() would throw a 403 for those channels.
            // Since Laravel only ever uses the default broadcaster's channel auth closures for broadcasting auth,
            // we only have to pass the channel closures to the default broadcaster.
            //
            // The $channels property and the channel() method aren't part of the Broadcaster contract -- they come
            // from the abstract Broadcaster class, so the closures can only be copied between broadcasters extending it
            // (which all of Laravel's default broadcasters, e.g. PusherBroadcaster, do).
            if ($centralBroadcaster instanceof Broadcaster && $tenantBroadcaster instanceof Broadcaster) {
                // invade() because channels can't be retrieved through any of the broadcaster's public methods
                $centralBroadcaster = invade($centralBroadcaster);

                foreach ($centralBroadcaster->channels as $channel => $callback) {
                    $tenantBroadcaster->channel($channel, $callback, $centralBroadcaster->retrieveChannelOptions($channel));
                }
            }

            return $tenantBroadcaster;
        });

        // Extending the binding doesn't update the Broadcast facade's cached instance,
        // so clear it to make the facade re-resolve to the tenant BroadcastManager instead of the central
        // one — e.g. in the Broadcast::auth() call in BroadcastController (/broadcasting/auth).
        Broadcast::clearResolvedInstance();
    }

    public function revert(): void
    {
        // Revert the bound BroadcastManager and Broadcaster singletons back to their original state
        $this->app->instance(BroadcastManager::class, $this->originalBroadcastManager);
        $this->app->instance(BroadcasterContract::class, $this->originalBroadcaster);

        // Clear the resolved Broadcast facade instance so that it gets re-resolved as the central BroadcastManager
        Broadcast::clearResolvedInstance();

        $this->unsetConfig();
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
