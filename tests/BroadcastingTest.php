<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Events\TenancyEnded;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Broadcasting\BroadcastManager;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Tests\Etc\TestingBroadcaster;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Overrides\TenancyBroadcastManager;
use Illuminate\Broadcasting\Broadcasters\NullBroadcaster;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\BroadcastingConfigBootstrapper;
use Illuminate\Contracts\Broadcasting\Broadcaster as BroadcasterContract;
use function Stancl\Tenancy\Tests\withTenantDatabases;

beforeEach(function () {
    withTenantDatabases();
    TenancyBroadcastManager::$tenantBroadcasters = ['pusher', 'ably'];

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
});

afterEach(function () {
    TenancyBroadcastManager::$tenantBroadcasters = ['pusher', 'ably'];
});

test('bound broadcaster instance is the same before initializing tenancy and after ending it', function() {
    config(['tenancy.bootstrappers' => [BroadcastingConfigBootstrapper::class]]);
    config(['broadcasting.default' => 'null']);
    TenancyBroadcastManager::$tenantBroadcasters[] = 'null';

    $originalBroadcaster = app(BroadcasterContract::class);

    tenancy()->initialize(Tenant::create());

    // TenancyBroadcastManager binds new broadcaster
    $tenantBroadcaster = app(BroadcastManager::class)->driver();

    expect($tenantBroadcaster)->not()->toBe($originalBroadcaster);

    tenancy()->end();

    expect($originalBroadcaster)->toBe(app(BroadcasterContract::class));
});

test('broadcasting config bootstrapper maps the config to broadcaster credentials correctly', function() {
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

    expect(app(BroadcastManager::class)->driver()->config['key'])->toBe('central_key');
    expect(app(BroadcasterContract::class)->config['key'])->toBe('central_key');
    expect(Broadcast::driver()->config['key'])->toBe('central_key');

    tenancy()->initialize($tenant1);

    expect(app(BroadcastManager::class)->driver()->config['key'])->toBe('tenant1_key');
    // Switching to tenant context makes the currently bound Broadcaster instance use the tenant's config
    expect(app(BroadcasterContract::class)->config['key'])->toBe('tenant1_key');
    // The Broadcast facade (used in BroadcastController::authenticate) uses the broadcaster with tenant config
    // instead of the stale broadcaster instance resolved before tenancy was initialized
    expect(Broadcast::driver()->config['key'])->toBe('tenant1_key');

    tenancy()->initialize($tenant2);

    // Switching to another tenant context makes the current broadcaster use the new tenant's config
    expect(app(BroadcastManager::class)->driver()->config['key'])->toBe('tenant2_key');
    expect(app(BroadcasterContract::class)->config['key'])->toBe('tenant2_key');
    expect(Broadcast::driver()->config['key'])->toBe('tenant2_key');

    tenancy()->end();

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

test('broadcasting channel helpers register channels correctly', function() {
    config([
        'broadcasting.default' => $driver = 'testing',
        'broadcasting.connections.testing.driver' => $driver,
    ]);

    config(['tenancy.bootstrappers' => [DatabaseTenancyBootstrapper::class]]);

    Schema::create('users', function (Blueprint $table) {
        $table->increments('id');
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->rememberToken();
        $table->timestamps();
    });

    $centralUser = User::create(['name' => 'central', 'email' => 'test@central.cz', 'password' => 'test']);
    $tenant = Tenant::create();

    migrateTenants();

    tenancy()->initialize($tenant);

    // Same ID as $centralUser
    $tenantUser = User::create(['name' => 'tenant', 'email' => 'test@tenant.cz', 'password' => 'test']);

    tenancy()->end();

    /** @var BroadcastManager $broadcastManager */
    $broadcastManager = app(BroadcastManager::class);

    // Use a driver with no channels
    $broadcastManager->extend($driver, fn () => new NullBroadcaster);

    $getChannels = fn (): Collection => $broadcastManager->driver($driver)->getChannels();

    expect($getChannels())->toBeEmpty();

    // Basic channel registration
    Broadcast::channel($channelName = 'user.{userName}', $channelClosure = function ($user, $userName) {
        return User::firstWhere('name', $userName)?->is($user) ?? false;
    });

    // Check if the channel is registered
    $centralChannelClosure = $getChannels()->first(fn ($closure, $name) => $name === $channelName);
    expect($centralChannelClosure)->not()->toBeNull();

    // Channel closures work as expected (running in central context)
    expect($centralChannelClosure($centralUser, $centralUser->name))->toBeTrue();
    expect($centralChannelClosure($centralUser, $tenantUser->name))->toBeFalse();

    // Register a tenant broadcasting channel (almost identical to the original channel, just able to accept the tenant key)
    tenant_channel($channelName, $channelClosure);

    // Tenant channel registered – its name is correctly prefixed ("{tenant}.user.{userId}")
    $tenantChannelClosure = $getChannels()->first(fn ($closure, $name) => $name === "{tenant}.$channelName");
    expect($tenantChannelClosure)->toBe($centralChannelClosure);

    // The tenant channels are prefixed with '{tenant}.'
    // They accept the tenant key, but their closures only run in tenant context when tenancy is initialized
    // The regular channels don't accept the tenant key, but they also respect the current context
    // The tenant key is used solely for the name prefixing – the closures can still run in the central context
    tenant_channel($channelName, $tenantChannelClosure = function ($user, $tenant, $userName) {
        return User::firstWhere('name', $userName)?->is($user) ?? false;
    });

    expect($tenantChannelClosure)->not()->toBe($centralChannelClosure);

    expect($tenantChannelClosure($centralUser, $tenant->getTenantKey(), $centralUser->name))->toBeTrue();
    expect($tenantChannelClosure($centralUser, $tenant->getTenantKey(), $tenantUser->name))->toBeFalse();

    tenancy()->initialize($tenant);

    // The channel closure runs in the central context
    // Only the central user is available
    expect($tenantChannelClosure($centralUser, $tenant->getTenantKey(), $tenantUser->name))->toBeFalse();
    expect($tenantChannelClosure($tenantUser, $tenant->getTenantKey(), $tenantUser->name))->toBeTrue();

    // Use a new channel instance to delete the previously registered channels before testing the universal_channel helper
    $broadcastManager->purge($driver);
    $broadcastManager->extend($driver, fn () => new NullBroadcaster);

    expect($getChannels())->toBeEmpty();

    // Global channel helper prefixes the channel name with 'global__'
    global_channel($channelName, $channelClosure);

    // Channel prefixed with 'global__' found
    $foundChannelClosure = $getChannels()->first(fn ($closure, $name) => $name === 'global__' . $channelName);
    expect($foundChannelClosure)->not()->toBeNull();
});
