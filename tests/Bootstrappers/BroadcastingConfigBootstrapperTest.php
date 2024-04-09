<?php

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Bootstrappers\BroadcastingConfigBootstrapper;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Overrides\TenancyBroadcastManager;
use Stancl\Tenancy\Tests\Etc\TestingBroadcaster;

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

test('BroadcastingConfigBootstrapper maps tenant broadcaster credentials to config as specified in the $credentialsMap property and reverts the config after ending tenancy', function() {
    config([
        'broadcasting.connections.testing.driver' => 'testing',
        'broadcasting.connections.testing.message' => $defaultMessage = 'default',
        'tenancy.bootstrappers' => [BroadcastingConfigBootstrapper::class],
    ]);

    BroadcastingConfigBootstrapper::$credentialsMap = [
        'broadcasting.connections.testing.message' => 'testing_broadcaster_message',
    ];

    $tenant = Tenant::create(['testing_broadcaster_message' => $tenantMessage = 'first testing']);
    $tenant2 = Tenant::create(['testing_broadcaster_message' => $secondTenantMessage = 'second testing']);

    tenancy()->initialize($tenant);

    expect(array_key_exists('testing_broadcaster_message', tenant()->getAttributes()))->toBeTrue();
    expect(config('broadcasting.connections.testing.message'))->toBe($tenantMessage);

    tenancy()->initialize($tenant2);

    expect(config('broadcasting.connections.testing.message'))->toBe($secondTenantMessage);

    tenancy()->end();

    expect(config('broadcasting.connections.testing.message'))->toBe($defaultMessage);
});

test('BroadcastingConfigBootstrapper makes the app use broadcasters with the correct credentials', function() {
    config([
        'broadcasting.default' => 'testing',
        'broadcasting.connections.testing.driver' => 'testing',
        'broadcasting.connections.testing.message' => $defaultMessage = 'default',
        'tenancy.bootstrappers' => [BroadcastingConfigBootstrapper::class],
    ]);

    TenancyBroadcastManager::$tenantBroadcasters[] = 'testing';
    BroadcastingConfigBootstrapper::$credentialsMap = [
        'broadcasting.connections.testing.message' => 'testing_broadcaster_message',
    ];

    $registerTestingBroadcaster = fn() => app(BroadcastManager::class)->extend('testing', fn ($app, $config) => new TestingBroadcaster($config['message']));

    $registerTestingBroadcaster();

    expect(invade(app(BroadcastManager::class)->driver())->message)->toBe($defaultMessage);

    $tenant = Tenant::create(['testing_broadcaster_message' => $tenantMessage = 'first testing']);
    $tenant2 = Tenant::create(['testing_broadcaster_message' => $secondTenantMessage = 'second testing']);

    tenancy()->initialize($tenant);
    $registerTestingBroadcaster();

    expect(invade(app(BroadcastManager::class)->driver())->message)->toBe($tenantMessage);

    tenancy()->initialize($tenant2);
    $registerTestingBroadcaster();

    expect(invade(app(BroadcastManager::class)->driver())->message)->toBe($secondTenantMessage);

    tenancy()->end();
    $registerTestingBroadcaster();

    expect(invade(app(BroadcastManager::class)->driver())->message)->toBe($defaultMessage);
});

