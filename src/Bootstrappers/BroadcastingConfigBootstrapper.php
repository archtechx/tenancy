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
        // The closure runs immediately (the extended singleton is already resolved), and it's also what makes
        // channel auth work in tenant context -- the broadcaster resolved here gets cached as the tenant
        // manager's default driver and receives the central broadcaster's auth state (see copyAuthState()).
        $this->app->extend(BroadcasterContract::class, function (BroadcasterContract $centralBroadcaster) {
            $tenantBroadcaster = $this->app->make(BroadcastManager::class)->connection();

            $this->copyAuthState($centralBroadcaster, $tenantBroadcaster);

            return $tenantBroadcaster;
        });

        // Extending the binding doesn't update the Broadcast facade's cached instance,
        // so clear it to make the facade re-resolve to the tenant BroadcastManager instead of the central
        // one — e.g. in the Broadcast::auth() call in BroadcastController (/broadcasting/auth).
        Broadcast::clearResolvedInstance();
    }

    /**
     * Copy the auth state (the channel auth closures, their options, and the authenticated user
     * callback) from one broadcaster to another. A freshly resolved broadcaster has no auth state,
     * so without the copying, channel auth and user auth would stop working (403) in tenant context.
     *
     * The auth state is stored on the abstract Broadcaster class, not in the Broadcaster
     * contract, and it's stored in protected properties. Because of that, we have
     * to check that both broadcasters are instances of the abstract Broadcaster class and
     * use invade() to access the protected properties (for the $channels property, there
     * is a public accessor -- getChannels() -- but since invade is already used here,
     * we access the property directly for consistency).
     */
    protected function copyAuthState(BroadcasterContract $from, BroadcasterContract $to): void
    {
        if (! $from instanceof Broadcaster || ! $to instanceof Broadcaster) {
            return;
        }

        $fromState = invade($from);
        $toState = invade($to);

        $toState->channels = $fromState->channels;
        $toState->channelOptions = $fromState->channelOptions;
        $toState->authenticatedUserCallback = $fromState->authenticatedUserCallback;
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
