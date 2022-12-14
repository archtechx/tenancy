<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenancyEnded;
use Illuminate\Support\Facades\Broadcast;
use Stancl\Tenancy\TenancyBroadcastManager;
use Illuminate\Broadcasting\BroadcastManager;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Tests\Etc\TestingBroadcaster;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Bootstrappers\BroadcastTenancyBootstrapper;
use Illuminate\Contracts\Broadcasting\Broadcaster as BroadcasterContract;

beforeEach(function() {
    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
});

test('bound broadcaster instance is the same before initializing tenancy and after ending it', function() {
    config(['broadcasting.default' => 'testing']);
    TenancyBroadcastManager::$tenantBroadcasters[] = 'testing';

    $originalBroadcaster = app(BroadcasterContract::class);

    tenancy()->initialize(Tenant::create());

    // TenancyBroadcastManager binds new broadcaster
    app(BroadcastManager::class)->driver();

    tenancy()->end();

    expect($originalBroadcaster)->toBe(app(BroadcasterContract::class));
});

test('new broadcasters get the channels from the previously bound broadcaster', function() {
    config([
        'broadcasting.default' => $driver = 'testing',
        'broadcasting.connections.testing.driver' => $driver,
    ]);

    TenancyBroadcastManager::$tenantBroadcasters[] = $driver;

    $registerTestingBroadcaster = fn() => app(BroadcastManager::class)->extend('testing', fn($app, $config) => new TestingBroadcaster($config['message']));
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
