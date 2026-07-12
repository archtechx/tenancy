<?php

use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenancyEnded;
use Illuminate\Broadcasting\BroadcastManager;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Tests\Etc\TestingBroadcaster;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Bootstrappers\BroadcastingConfigBootstrapper;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Contracts\Broadcasting\Broadcaster as BroadcasterContract;

$cleanup = function () {
    BroadcastingConfigBootstrapper::$broadcaster = null;
    BroadcastingConfigBootstrapper::$credentialsMap = [];
    BroadcastingConfigBootstrapper::$mapPresets = [
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
};

beforeEach(function () use ($cleanup) {
    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);

    $cleanup();
});

afterEach($cleanup);

test('BroadcastingConfigBootstrapper binds a fresh BroadcastManager and reverts the binding when tenancy is ended', function () {
    config(['tenancy.bootstrappers' => [BroadcastingConfigBootstrapper::class]]);

    $centralManager = app(BroadcastManager::class);

    tenancy()->initialize(Tenant::create());

    expect(app(BroadcastManager::class))
        ->toBeInstanceOf(BroadcastManager::class)
        ->not()->toBe($centralManager);

    tenancy()->end();

    expect(app(BroadcastManager::class))->toBe($centralManager);
});

test('ending tenancy reverts the bound broadcaster to the original instance', function () {
    config([
        'tenancy.bootstrappers' => [BroadcastingConfigBootstrapper::class],
        'broadcasting.default' => 'testing',
        'broadcasting.connections.testing.driver' => 'testing',
    ]);

    app(BroadcastManager::class)->extend('testing', fn ($app, $config) => new TestingBroadcaster('testing', $config));

    $originalBroadcaster = app(BroadcasterContract::class);

    tenancy()->initialize(Tenant::create());

    // BroadcastingConfigBootstrapper binds a freshly resolved broadcaster
    expect(app(BroadcasterContract::class))->not()->toBe($originalBroadcaster);

    // The bound broadcaster is the same instance as the tenant BroadcastManager's default driver
    expect(app(BroadcasterContract::class))->toBe(app(BroadcastManager::class)->driver());

    tenancy()->end();

    // Ending tenancy reverts the binding back to the original broadcaster instance
    expect($originalBroadcaster)->toBe(app(BroadcasterContract::class));
});

test('BroadcastingConfigBootstrapper maps tenant properties to broadcaster credentials correctly', function (string $driver) {
    config([
        'broadcasting.default' => $driver,
        "broadcasting.connections.{$driver}.key" => 'central_key',
        'tenancy.bootstrappers' => [
            BroadcastingConfigBootstrapper::class,
        ],
    ]);

    if ($driver === 'custom') {
        config(['broadcasting.connections.custom.driver' => 'custom']);
    }

    BroadcastingConfigBootstrapper::$credentialsMap["broadcasting.connections.{$driver}.key"] = 'testing_key';

    app(BroadcastManager::class)->extend($driver, fn ($app, $config) => new TestingBroadcaster('testing', $config));

    $tenant1 = Tenant::create(['testing_key' => 'tenant1_key']);
    $tenant2 = Tenant::create(['testing_key' => 'tenant2_key']);

    expect(config("broadcasting.connections.{$driver}.key"))->toBe('central_key');
    expect(app(BroadcastManager::class)->driver()->config['key'])->toBe('central_key');
    expect(app(BroadcasterContract::class)->config['key'])->toBe('central_key');
    expect(Broadcast::driver()->config['key'])->toBe('central_key');

    tenancy()->initialize($tenant1);

    // Tenant's testing_key property is mapped to the current broadcasting connection key
    expect(config("broadcasting.connections.{$driver}.key"))->toBe('tenant1_key');
    expect(app(BroadcastManager::class)->driver()->config['key'])->toBe('tenant1_key');
    // Switching to tenant context makes the currently bound Broadcaster instance use the tenant's config
    expect(app(BroadcasterContract::class)->config['key'])->toBe('tenant1_key');
    // The Broadcast facade (used in BroadcastController::authenticate) uses the broadcaster with tenant config
    // instead of the stale broadcaster instance resolved before tenancy was initialized
    expect(Broadcast::driver()->config['key'])->toBe('tenant1_key');

    tenancy()->initialize($tenant2);

    expect(config("broadcasting.connections.{$driver}.key"))->toBe('tenant2_key');
    // Switching to another tenant context makes the current broadcaster use the new tenant's config
    expect(app(BroadcastManager::class)->driver()->config['key'])->toBe('tenant2_key');
    expect(app(BroadcasterContract::class)->config['key'])->toBe('tenant2_key');
    expect(Broadcast::driver()->config['key'])->toBe('tenant2_key');

    $tenant2->update(['testing_key' => 'new_tenant2_key']);

    // Reinitialize tenancy to apply the tenant property update to config
    tenancy()->end();
    tenancy()->initialize($tenant2);

    expect(config("broadcasting.connections.{$driver}.key"))->toBe('new_tenant2_key');
    expect(app(BroadcastManager::class)->driver()->config['key'])->toBe('new_tenant2_key');
    expect(app(BroadcasterContract::class)->config['key'])->toBe('new_tenant2_key');
    expect(Broadcast::driver()->config['key'])->toBe('new_tenant2_key');

    tenancy()->initialize($tenant1);

    // Direct config changes aren't picked up by the broadcasters -- they get resolved
    // using the config mapped from tenant properties at initialization and stay cached
    // until tenancy is reinitialized.
    config(["broadcasting.connections.{$driver}.key" => 'new_tenant1_key']);

    expect(config("broadcasting.connections.{$driver}.key"))->toBe('new_tenant1_key');
    expect(app(BroadcastManager::class)->driver()->config['key'])->toBe('tenant1_key');
    expect(app(BroadcasterContract::class)->config['key'])->toBe('tenant1_key');
    expect(Broadcast::driver()->config['key'])->toBe('tenant1_key');

    // Initializing tenancy for a tenant without the mapped property keeps the central config value
    tenancy()->initialize(Tenant::create());

    expect(config("broadcasting.connections.{$driver}.key"))->toBe('central_key');
    expect(app(BroadcastManager::class)->driver()->config['key'])->toBe('central_key');
    expect(app(BroadcasterContract::class)->config['key'])->toBe('central_key');

    tenancy()->end();

    expect(config("broadcasting.connections.{$driver}.key"))->toBe('central_key');
    // Ending tenancy reverts the broadcaster changes
    expect(app(BroadcastManager::class)->driver()->config['key'])->toBe('central_key');
    expect(app(BroadcasterContract::class)->config['key'])->toBe('central_key');
    expect(Broadcast::driver()->config['key'])->toBe('central_key');
})->with([
    'pusher',
    'ably',
    'reverb',
    'custom',
]);

test('tenant broadcast manager receives the custom driver creators of the central broadcast manager', function () {
    config([
        'tenancy.bootstrappers' => [
            BroadcastingConfigBootstrapper::class,
        ],
    ]);

    $tenant = Tenant::create();
    $tenant2 = Tenant::create();

    app(BroadcastManager::class)->extend('testing', fn($app, $config) => new TestingBroadcaster('testing', $config));

    $originalDrivers = array_keys(invade(app(BroadcastManager::class))->customCreators);

    expect($originalDrivers)->toContain('testing');

    tenancy()->initialize($tenant);

    app(BroadcastManager::class)->extend(
        'testing-tenant1',
        fn($app, $config) => new TestingBroadcaster('testing-tenant1', $config)
    );

    // Current BroadcastManager instance has the original custom creators plus the newly registered testing-tenant1 creator
    expect(array_keys(invade(app(BroadcastManager::class))->customCreators))->toEqualCanonicalizing([...$originalDrivers, 'testing-tenant1']);

    tenancy()->initialize($tenant2);

    // Current BroadcastManager only has the original custom creators,
    // the creator added in the previous tenant's context doesn't persist.
    expect(array_keys(invade(app(BroadcastManager::class))->customCreators))->toEqualCanonicalizing($originalDrivers);

    tenancy()->end();

    // Ending tenancy reverts the BroadcastManager binding back to the original state,
    // the creator registered in the tenant context doesn't persist.
    expect(array_keys(invade(app(BroadcastManager::class))->customCreators))->toEqualCanonicalizing($originalDrivers);
});

test('tenant broadcasters receive the channel auth closures from the broadcaster bound in central context', function () {
    config([
        'tenancy.bootstrappers' => [BroadcastingConfigBootstrapper::class],
        'broadcasting.default' => 'testing',
        'broadcasting.connections.testing.driver' => 'testing',
    ]);

    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();

    app(BroadcastManager::class)->extend('testing', fn () => new TestingBroadcaster('testing'));
    $getCurrentChannelsFromBoundBroadcaster = fn () => array_keys(invade(app(BroadcasterContract::class))->channels);
    $getCurrentChannelsThroughManager = fn () => array_keys(invade(app(BroadcastManager::class)->driver())->channels);

    Broadcast::channel($channel = 'testing-channel', $callback = fn () => true, $options = ['guards' => ['web']]);

    expect($channel)
        ->toBeIn($getCurrentChannelsThroughManager())
        ->toBeIn($getCurrentChannelsFromBoundBroadcaster());

    tenancy()->initialize($tenant1);

    expect($channel)
        ->toBeIn($getCurrentChannelsThroughManager())
        ->toBeIn($getCurrentChannelsFromBoundBroadcaster());

    // The channel auth closure and the channel options are copied to the tenant broadcaster as-is
    expect(invade(app(BroadcasterContract::class))->channels[$channel])->toBe($callback);
    expect(invade(app(BroadcasterContract::class))->retrieveChannelOptions($channel))->toBe($options);

    tenancy()->initialize($tenant2);

    expect($channel)
        ->toBeIn($getCurrentChannelsThroughManager())
        ->toBeIn($getCurrentChannelsFromBoundBroadcaster());

    tenancy()->end();

    expect($channel)
        ->toBeIn($getCurrentChannelsThroughManager())
        ->toBeIn($getCurrentChannelsFromBoundBroadcaster());
});

test('channels registered in tenant context persist within that context but do not leak into other contexts', function () {
    config([
        'tenancy.bootstrappers' => [BroadcastingConfigBootstrapper::class],
        'broadcasting.default' => 'testing',
        'broadcasting.connections.testing.driver' => 'testing',
    ]);

    app(BroadcastManager::class)->extend('testing', fn($app, $config) => new TestingBroadcaster('testing', $config));

    Broadcast::channel('central-channel', fn () => true);

    tenancy()->initialize(Tenant::create());

    Broadcast::channel('tenant-channel', fn () => true);

    // Retrieving the broadcaster again (e.g. on Broadcast::auth() during a /broadcasting/auth request)
    // returns the cached broadcaster, so the channel registered in tenant context is still available
    expect(array_keys(invade(Broadcast::driver())->channels))
        ->toContain('central-channel')
        ->toContain('tenant-channel');

    // The channel registered in the previous tenant's context doesn't leak to another tenant's broadcaster
    tenancy()->initialize(Tenant::create());

    expect(array_keys(invade(Broadcast::driver())->channels))
        ->toContain('central-channel')
        ->not()->toContain('tenant-channel');

    tenancy()->end();

    // The channel registered in tenant context doesn't leak to the central broadcaster
    expect(array_keys(invade(Broadcast::driver())->channels))
        ->toContain('central-channel')
        ->not()->toContain('tenant-channel');
});

test('mappings specified in credentialsMap override default mapPresets', function ($driver) {
    config([
        'tenancy.bootstrappers' => [BroadcastingConfigBootstrapper::class],
        'broadcasting.default' => $driver,
    ]);

    // The preset mapping for the tested broadcaster (this is the default, we only set it here for clarity)
    BroadcastingConfigBootstrapper::$mapPresets[$driver]["broadcasting.connections.{$driver}.key"] = "{$driver}_key";

    // Custom mapping specified in credentialsMap should override the preset mapping for the tested broadcaster
    BroadcastingConfigBootstrapper::$credentialsMap["broadcasting.connections.{$driver}.key"] = 'broadcasting_key';

    app(BroadcastManager::class)->extend($driver, fn($app, $config) => new TestingBroadcaster('testing'));

    $tenant = Tenant::create([
        "{$driver}_key" => 'preset_value',
        'broadcasting_key' => 'custom_value',
    ]);

    tenancy()->initialize($tenant);

    expect(config("broadcasting.connections.{$driver}.key"))->toBe('custom_value');
})->with([
    'pusher',
    'ably',
    'reverb',
]);

test('initializing tenancy does not fail when the broadcaster does not extend the abstract Broadcaster class', function () {
    config([
        'tenancy.bootstrappers' => [BroadcastingConfigBootstrapper::class],
        'broadcasting.default' => 'contract',
        'broadcasting.connections.contract.driver' => 'contract',
    ]);

    $contractBroadcaster = new class implements BroadcasterContract {
        public function auth($request) {}
        public function validAuthenticationResponse($request, $result) {}
        public function broadcast(array $channels, $event, array $payload = []) {}
    };

    app(BroadcastManager::class)->extend('contract', fn () => clone $contractBroadcaster);

    $centralBroadcaster = app(BroadcasterContract::class);

    // Channel auth closures only exist on broadcasters extending the abstract Broadcaster class,
    // so the bootstrapper skips copying them instead of failing.
    tenancy()->initialize(Tenant::create());

    expect(app(BroadcasterContract::class))
        ->toBeInstanceOf(get_class($contractBroadcaster))
        ->not()->toBe($centralBroadcaster);
});

test('setting the broadcaster property overrides which map preset is used', function () {
    config([
        'tenancy.bootstrappers' => [BroadcastingConfigBootstrapper::class],
        'broadcasting.default' => 'testing',
        'broadcasting.connections.testing.driver' => 'testing',
        'broadcasting.connections.pusher.key' => 'central_key',
    ]);

    app(BroadcastManager::class)->extend('testing', fn () => new TestingBroadcaster('testing'));

    // Use the pusher preset even though the default connection isn't pusher
    BroadcastingConfigBootstrapper::$broadcaster = 'pusher';

    tenancy()->initialize(Tenant::create(['pusher_key' => 'tenant_key']));

    expect(config('broadcasting.connections.pusher.key'))->toBe('tenant_key');
});
