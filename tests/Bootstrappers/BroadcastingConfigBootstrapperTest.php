<?php

use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenancyEnded;
use Illuminate\Broadcasting\BroadcastManager;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Tests\Etc\TestingBroadcaster;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Overrides\TenancyBroadcastManager;
use Stancl\Tenancy\Bootstrappers\BroadcastingConfigBootstrapper;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Contracts\Broadcasting\Broadcaster as BroadcasterContract;

beforeEach(function () {
    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);

    BroadcastingConfigBootstrapper::$broadcaster = null;
    BroadcastingConfigBootstrapper::$credentialsMap = [];
    TenancyBroadcastManager::$tenantBroadcasters = ['pusher', 'ably', 'reverb'];
});

afterEach(function () {
    BroadcastingConfigBootstrapper::$broadcaster = null;
    BroadcastingConfigBootstrapper::$credentialsMap = [];
    TenancyBroadcastManager::$tenantBroadcasters = ['pusher', 'ably', 'reverb'];
});

test('BroadcastingConfigBootstrapper binds TenancyBroadcastManager to BroadcastManager and reverts the binding when tenancy is ended', function() {
    config(['tenancy.bootstrappers' => [BroadcastingConfigBootstrapper::class]]);

    expect(app(BroadcastManager::class))
        ->toBeInstanceOf(BroadcastManager::class)
        ->not()->toBeInstanceOf(TenancyBroadcastManager::class);

    tenancy()->initialize(Tenant::create());

    expect(app(BroadcastManager::class))->toBeInstanceOf(TenancyBroadcastManager::class);

    tenancy()->end();

    expect(app(BroadcastManager::class))
        ->toBeInstanceOf(BroadcastManager::class)
        ->not()->toBeInstanceOf(TenancyBroadcastManager::class);
});

test('BroadcastingConfigBootstrapper maps tenant properties to broadcaster credentials correctly', function(string $driver) {
    config([
        'broadcasting.default' => $driver,
        "broadcasting.connections.{$driver}.key" => 'central_key',
        'tenancy.bootstrappers' => [
            BroadcastingConfigBootstrapper::class,
        ],
    ]);

    if ($driver === 'custom') {
        config(['broadcasting.connections.custom.driver' => 'custom']);

        // Custom driver, not included in TenancyBroadcastManager::$tenantBroadcasters by default
        TenancyBroadcastManager::$tenantBroadcasters = ['custom'];
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

    // When updating tenant properties without reinitializing, the tenant property update doesn't update the config,
    // so the config has to be modified manually. Only methods that use TenancyBroadcastManager::get()
    // will use the updated credentials without needing to reinitialize tenancy (e.g. the bound
    // BroadcasterContract instance will still use the original credentials, even after config gets updated directly).
    config(["broadcasting.connections.{$driver}.key" => 'new_tenant1_key']);

    expect(app(BroadcastManager::class)->driver()->config['key'])->toBe('new_tenant1_key');
    expect(Broadcast::driver()->config['key'])->toBe('new_tenant1_key');

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
    'custom', // Except for this custom driver, assume that the drivers are included in TenancyBroadcastManager::$tenantBroadcasters by default
]);

test('tenant broadcast manager receives the custom driver creators of the central broadcast manager', function() {
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
    expect(array_keys(invade(app(BroadcastManager::class))->customCreators))->toBe([...$originalDrivers, 'testing-tenant1']);

    tenancy()->initialize($tenant2);

    // Current BroadcastManager only has the original custom creators,
    // the creator added in the previous tenant's context doesn't persist.
    expect(array_keys(invade(app(BroadcastManager::class))->customCreators))->toBe($originalDrivers);

    tenancy()->end();

    // Ending tenancy reverts the BroadcastManager binding back to the original state,
    // the creator registered in the tenant context doesn't persist.
    expect(array_keys(invade(app(BroadcastManager::class))->customCreators))->toBe($originalDrivers);
});

test('tenant broadcasters receive the channels from the broadcaster bound in central context', function(string $driver) {
    config([
        'tenancy.bootstrappers' => [BroadcastingConfigBootstrapper::class],
        'broadcasting.default' => $driver,
    ]);

    if ($driver === 'custom') {
        config(['broadcasting.connections.custom.driver' => 'custom']);

        // Custom driver, not included in TenancyBroadcastManager::$tenantBroadcasters by default
        TenancyBroadcastManager::$tenantBroadcasters = ['custom'];
    }

    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();

    app(BroadcastManager::class)->extend($driver, fn($app, $config) => new TestingBroadcaster('testing'));
    $getCurrentChannelsFromBoundBroadcaster = fn() => array_keys(invade(app(BroadcasterContract::class))->channels);
    $getCurrentChannelsThroughManager = fn() => array_keys(invade(app(BroadcastManager::class)->driver())->channels);

    Broadcast::channel($channel = 'testing-channel', fn() => true);

    expect($channel)
        ->toBeIn($getCurrentChannelsThroughManager())
        ->toBeIn($getCurrentChannelsFromBoundBroadcaster());

    tenancy()->initialize($tenant1);

    expect($channel)
        ->toBeIn($getCurrentChannelsThroughManager())
        ->toBeIn($getCurrentChannelsFromBoundBroadcaster());

    tenancy()->initialize($tenant2);

    expect($channel)
        ->toBeIn($getCurrentChannelsThroughManager())
        ->toBeIn($getCurrentChannelsFromBoundBroadcaster());

    tenancy()->end();

    expect($channel)
        ->toBeIn($getCurrentChannelsThroughManager())
        ->toBeIn($getCurrentChannelsFromBoundBroadcaster());
})->with([
    'pusher',
    'ably',
    'reverb',
    'custom', // Except for this custom driver, assume that the drivers are included in TenancyBroadcastManager::$tenantBroadcasters by default
]);

test('mappings specified in credentialsMap override default mapPresets', function($driver) {
    config([
        'tenancy.bootstrappers' => [BroadcastingConfigBootstrapper::class],
        'broadcasting.default' => $driver,
    ]);

    // Preset used for broadcasters included in TenancyBroadcastManager::$tenantBroadcasters by default.
    // This is the default for all tenant broadcasters, but we set it here for clarity.
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
})->with(['pusher', 'ably', 'reverb']);
