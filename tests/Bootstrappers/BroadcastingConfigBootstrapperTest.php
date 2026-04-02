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

    BroadcastingConfigBootstrapper::$credentialsMap = [];
    TenancyBroadcastManager::$tenantBroadcasters = ['pusher', 'ably'];
});

afterEach(function () {
    BroadcastingConfigBootstrapper::$credentialsMap = [];
    TenancyBroadcastManager::$tenantBroadcasters = ['pusher', 'ably'];
});

test('BroadcastingConfigBootstrapper binds TenancyBroadcastManager to BroadcastManager and reverts the binding when tenancy is ended', function() {
    config(['tenancy.bootstrappers' => [BroadcastingConfigBootstrapper::class]]);

    expect(app(BroadcastManager::class))->toBeInstanceOf(BroadcastManager::class);

    tenancy()->initialize(Tenant::create());

    expect(app(BroadcastManager::class))->toBeInstanceOf(TenancyBroadcastManager::class);

    tenancy()->end();

    expect(app(BroadcastManager::class))->toBeInstanceOf(BroadcastManager::class);
});

test('BroadcastingConfigBootstrapper maps tenant properties to broadcaster credentials correctly', function() {
    config([
        'broadcasting.default' => $driver = 'testing',
        'broadcasting.connections.testing.driver' => $driver,
        'broadcasting.connections.testing.key' => 'central_key',
        'tenancy.bootstrappers' => [
            BroadcastingConfigBootstrapper::class,
        ],
    ]);

    BroadcastingConfigBootstrapper::$credentialsMap['broadcasting.connections.testing.key'] = 'testing_key';

    // Register the testing broadcaster
    app(BroadcastManager::class)->extend('testing', fn($app, $config) => new TestingBroadcaster('testing', $config));

    $tenant1 = Tenant::create(['testing_key' => 'tenant1_key']);
    $tenant2 = Tenant::create(['testing_key' => 'tenant2_key']);

    expect(config('broadcasting.connections.testing.key'))->toBe('central_key');
    expect(app(BroadcastManager::class)->driver()->config['key'])->toBe('central_key');
    expect(app(BroadcasterContract::class)->config['key'])->toBe('central_key');
    expect(Broadcast::driver()->config['key'])->toBe('central_key');

    tenancy()->initialize($tenant1);

    expect(array_key_exists('testing_key', tenant()->getAttributes()))->toBeTrue();
    // Tenant's testing_key property is mapped to broadcasting.connections.testing.key config value
    expect(config('broadcasting.connections.testing.key'))->toBe('tenant1_key');
    expect(app(BroadcastManager::class)->driver()->config['key'])->toBe('tenant1_key');
    // Switching to tenant context makes the currently bound Broadcaster instance use the tenant's config
    expect(app(BroadcasterContract::class)->config['key'])->toBe('tenant1_key');
    // The Broadcast facade (used in BroadcastController::authenticate) uses the broadcaster with tenant config
    // instead of the stale broadcaster instance resolved before tenancy was initialized
    expect(Broadcast::driver()->config['key'])->toBe('tenant1_key');

    tenancy()->initialize($tenant2);

    expect(array_key_exists('testing_key', tenant()->getAttributes()))->toBeTrue();
    expect(config('broadcasting.connections.testing.key'))->toBe('tenant2_key');
    // Switching to another tenant context makes the current broadcaster use the new tenant's config
    expect(app(BroadcastManager::class)->driver()->config['key'])->toBe('tenant2_key');
    expect(app(BroadcasterContract::class)->config['key'])->toBe('tenant2_key');
    expect(Broadcast::driver()->config['key'])->toBe('tenant2_key');

    tenancy()->end();

    expect(config('broadcasting.connections.testing.key'))->toBe('central_key');
    // Ending tenancy reverts the broadcaster changes
    expect(app(BroadcastManager::class)->driver()->config['key'])->toBe('central_key');
    expect(app(BroadcasterContract::class)->config['key'])->toBe('central_key');
    expect(Broadcast::driver()->config['key'])->toBe('central_key');
});

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

test('tenant broadcasters receive the channels from the broadcaster bound in central context', function() {
    config(['tenancy.bootstrappers' => [BroadcastingConfigBootstrapper::class]]);
    config([
        'broadcasting.default' => $driver = 'testing',
        'broadcasting.connections.testing.driver' => $driver,
    ]);

    TenancyBroadcastManager::$tenantBroadcasters[] = $driver;

    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();

    app(BroadcastManager::class)->extend('testing', fn($app, $config) => new TestingBroadcaster('testing'));
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
});
