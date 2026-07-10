<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Broadcast;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Overrides\TenancyBroadcastManager;

/**
 * Maps tenant properties to broadcasting config and overrides
 * the BroadcastManager and Broadcaster bindings with tenant-aware instances.
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
     *
     * $tenant->tenant_property will be mapped to config('config.key.name') when tenancy is initialized.
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

        // Make BroadcastManager resolve to a fresh TenancyBroadcastManager. The new manager:
        // - has no cached broadcasters, so its broadcasters get resolved using the updated (tenant)
        //   broadcasting config and stay cached for the duration of the tenant's context
        // - makes the tenant broadcasters inherit the channels of the original (central) broadcaster
        //   (since newly resolved broadcasters don't receive any channels by default, broadcasting on
        //   channels registered in central context, e.g. in routes/channels.php, would otherwise not
        //   work with the tenant broadcasters)
        $this->app->extend(BroadcastManager::class, function (BroadcastManager $broadcastManager) {
            $originalCustomCreators = invade($broadcastManager)->customCreators;
            $tenantBroadcastManager = new TenancyBroadcastManager($this->app);

            // Make TenancyBroadcastManager inherit the custom driver creators registered in the central context
            // so that custom drivers work in tenant context without having to re-register the creators manually.
            foreach ($originalCustomCreators as $driver => $closure) {
                $tenantBroadcastManager->extend($driver, $closure);
            }

            return $tenantBroadcastManager;
        });

        // Swap the currently bound Broadcaster singleton (resolved earlier with the central credentials)
        // for the tenant BroadcastManager's default broadcaster, so that anything resolving the Broadcaster
        // contract gets the same tenant broadcaster that the manager uses, instead of the stale central one.
        $this->app->extend(Broadcaster::class, function () {
            return $this->app->make(BroadcastManager::class)->connection();
        });

        // Extending the binding doesn't update the Broadcast facade's cached instance,
        // so clear it to make the facade re-resolve to TenancyBroadcastManager instead of the central
        // BroadcastManager — e.g. in the Broadcast::auth() call in BroadcastController (/broadcasting/auth).
        Broadcast::clearResolvedInstance();
    }

    public function revert(): void
    {
        // Revert the bound BroadcastManager and Broadcaster singletons back to their original state
        $this->app->singleton(BroadcastManager::class, fn () => $this->originalBroadcastManager);
        $this->app->singleton(Broadcaster::class, fn () => $this->originalBroadcaster);

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
