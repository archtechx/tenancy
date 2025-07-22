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

test('new broadcasters get the channels from the previously bound broadcaster', function() {
    config(['tenancy.bootstrappers' => [BroadcastingConfigBootstrapper::class]]);
    config([
        'broadcasting.default' => $driver = 'testing',
        'broadcasting.connections.testing.driver' => $driver,
    ]);

    TenancyBroadcastManager::$tenantBroadcasters[] = $driver;

    $registerTestingBroadcaster = fn() => app(BroadcastManager::class)->extend('testing', fn($app, $config) => new TestingBroadcaster('testing'));
    $getCurrentChannels = fn() => array_keys(invade(app(BroadcastManager::class)->driver())->channels);

    $registerTestingBroadcaster();
    Broadcast::channel($channel = 'testing-channel', fn() => true);

    expect($channel)->toBeIn($getCurrentChannels());

    tenancy()->initialize(Tenant::create());
    $registerTestingBroadcaster();

    expect($channel)->toBeIn($getCurrentChannels());

    tenancy()->end();
    $registerTestingBroadcaster();

    expect($channel)->toBeIn($getCurrentChannels());
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

    // Use a new channel instance to delete the previously registered channels before testing the univeresal_channel helper
    $broadcastManager->purge($driver);
    $broadcastManager->extend($driver, fn () => new NullBroadcaster);

    expect($getChannels())->toBeEmpty();

    // Global channel helper prefixes the channel name with 'global__'
    global_channel($channelName, $channelClosure);

    // Channel prefixed with 'global__' found
    $foundChannelClosure = $getChannels()->first(fn ($closure, $name) => $name === 'global__' . $channelName);
    expect($foundChannelClosure)->not()->toBeNull();
});
