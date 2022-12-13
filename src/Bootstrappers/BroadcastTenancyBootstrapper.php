<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Illuminate\Config\Repository;
use Stancl\Tenancy\Contracts\Tenant;
use Illuminate\Foundation\Application;
use Stancl\Tenancy\TenancyBroadcastManager;
use Illuminate\Broadcasting\BroadcastManager;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Illuminate\Contracts\Broadcasting\Broadcaster;

class BroadcastTenancyBootstrapper implements TenancyBootstrapper
{
    /**
     * Tenant properties to be mapped to config (similarly to the TenantConfig feature).
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
        static::$credentialsMap = array_merge(static::$credentialsMap, static::$mapPresets[static::$broadcaster] ?? []);
    }

    public function bootstrap(Tenant $tenant): void
    {
        $this->originalBroadcastManager = $this->app->make(BroadcastManager::class);
        $this->originalBroadcaster = $this->app->make(Broadcaster::class);

        $this->setConfig($tenant);

        $this->app->extend(BroadcastManager::class, function (BroadcastManager $broadcastManager) {
            $tenancyBroadcastManager = new TenancyBroadcastManager($this->app);

            return $tenancyBroadcastManager->setDriver($broadcastManager->getDefaultDriver(), $broadcastManager->driver());
        });
    }

    public function revert(): void
    {
        $this->app->singleton(BroadcastManager::class, fn (Application $app) => $this->originalBroadcastManager);
        $this->app->singleton(Broadcaster::class, fn (Application $app) => $this->originalBroadcaster);

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
