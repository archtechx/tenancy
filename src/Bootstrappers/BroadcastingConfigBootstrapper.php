<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastingFactory;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Broadcast;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Overrides\TenancyBroadcastManager;

/**
 * Maps tenant properties to broadcasting config and overrides
 * the BroadcastManager binding with TenancyBroadcastManager in tenant context.
 *
 * @see TenancyBroadcastManager
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
     */
    public static array $credentialsMap = [];

    public static string|null $broadcaster = null;

    protected array $originalConfig = [];
    protected BroadcastManager|null $originalBroadcastManager = null;
    protected Broadcaster|null $originalBroadcaster = null;

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
        $this->originalBroadcaster = $this->app->make(Broadcaster::class);

        $this->setConfig($tenant);

        // Make BroadcastManager resolve to TenancyBroadcastManager which always re-resolves the used broadcasters so that
        // the credentials used by broadcasters are always up-to-date with the config when retrieving the broadcasters using
        // the manager and gives the channels of the broadcaster from central context to the newly resolved broadcasters in tenant context.
        $this->app->extend(BroadcastManager::class, function (BroadcastManager $broadcastManager) {
            $originalCustomCreators = invade($broadcastManager)->customCreators;
            $tenantBroadcastManager = new TenancyBroadcastManager($this->app);

            // TenancyBroadcastManager inherits the custom driver creators registered in the central context so that
            // custom drivers work in tenant context without having to re-register the creators manually.
            foreach ($originalCustomCreators as $driver => $closure) {
                $tenantBroadcastManager->extend($driver, $closure);
            }

            return $tenantBroadcastManager;
        });

        // Swap currently bound Broadcaster instance for one that's resolved through the tenant broadcast manager.
        // Note that updating broadcasting config (credentials) in tenant context doesn't update the credentials
        // used by the bound Broadcaster instance. If you need to e.g. send a notification in response to
        // updating tenant's broadcasting credentials in tenant context, it's recommended to
        // reinitialize tenancy after updating the credentials.
        $this->app->extend(Broadcaster::class, function (Broadcaster $broadcaster) {
            return $this->app->make(BroadcastManager::class)->connection();
        });

        // Clear the resolved Broadcast facade's Illuminate\Contracts\Broadcasting\Factory instance
        // so that it gets re-resolved as TenancyBroadcastManager instead of the central BroadcastManager
        // when used e.g. in the Broadcast::auth() call in BroadcastController::authenticate (/broadcasting/auth).
        Broadcast::clearResolvedInstance(BroadcastingFactory::class);
    }

    public function revert(): void
    {
        // Change the BroadcastManager and Broadcaster singletons back to what they were before initializing tenancy
        $this->app->singleton(BroadcastManager::class, fn (Application $app) => $this->originalBroadcastManager);
        $this->app->singleton(Broadcaster::class, fn (Application $app) => $this->originalBroadcaster);

        // Clear the resolved Broadcast facade instance so that it gets re-resolved as the central broadcast manager
        Broadcast::clearResolvedInstance(BroadcastingFactory::class);

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
