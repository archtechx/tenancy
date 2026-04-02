<?php

use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Events\TenantCreated;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Broadcasting\BroadcastManager;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Tests\Etc\TestingBroadcaster;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\BroadcastChannelPrefixBootstrapper;
use function Stancl\Tenancy\Tests\pest;
use Illuminate\Broadcasting\Broadcasters\NullBroadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Collection;

beforeEach(function () {
    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);

    Event::listen(
        TenantCreated::class,
        JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
            return $event->tenant;
        })->toListener()
    );
});

test('BroadcastChannelPrefixBootstrapper prefixes the channels events are broadcast on while tenancy is initialized', function() {
    config([
        'broadcasting.default' => $driver = 'testing',
        'broadcasting.connections.testing.driver' => $driver,
    ]);

    // Use custom broadcaster
    app(BroadcastManager::class)->extend($driver, fn () => new TestingBroadcaster('original broadcaster'));

    config(['tenancy.bootstrappers' => [BroadcastChannelPrefixBootstrapper::class, DatabaseTenancyBootstrapper::class]]);

    Schema::create('users', function (Blueprint $table) {
        $table->increments('id');
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->rememberToken();
        $table->timestamps();
    });

    $broadcaster = app(BroadcastManager::class)->driver();

    $tenant = Tenant::create();
    $tenant2 = Tenant::create();

    pest()->artisan('tenants:migrate');

    // Set up the 'testing' broadcaster override
    // Identical to the default Pusher override (BroadcastChannelPrefixBootstrapper::pusher())
    // Except for the parent class (TestingBroadcaster instead of PusherBroadcaster)
    BroadcastChannelPrefixBootstrapper::$broadcasterOverrides['testing'] = function (BroadcastManager $broadcastManager) {
         $broadcastManager->extend('testing', function ($app, $config) {
            return new class('tenant broadcaster') extends TestingBroadcaster {
                protected function formatChannels(array $channels)
                    {
                        $formatChannel = function (string $channel) {
                            $prefixes = ['private-', 'presence-'];
                            $defaultPrefix = '';

                            foreach ($prefixes as $prefix) {
                                if (str($channel)->startsWith($prefix)) {
                                    $defaultPrefix = $prefix;
                                    break;
                                }
                            }

                            // Skip prefixing channels flagged with the global channel prefix
                            if (! str($channel)->startsWith('global__')) {
                                $channel = str($channel)->after($defaultPrefix)->prepend($defaultPrefix . tenant()->getTenantKey() . '.');
                            }

                            return (string) $channel;
                        };

                        return array_map($formatChannel, parent::formatChannels($channels));
                    }
            };
        });
    };

    auth()->login($user = User::create(['name' => 'central', 'email' => 'test@central.cz', 'password' => 'test']));

    // The channel names used for testing the formatChannels() method (not real channels)
    $channelNames = [
        'channel',
        'global__channel', // Channels prefixed with 'global__' shouldn't get prefixed with the tenant key
        'private-user.' . $user->id,
    ];

    // formatChannels doesn't prefix the channel names until tenancy is initialized
    expect(invade(app(BroadcastManager::class)->driver())->formatChannels($channelNames))->toEqual($channelNames);

    tenancy()->initialize($tenant);

    $tenantBroadcaster = app(BroadcastManager::class)->driver();

    auth()->login($tenantUser = User::create(['name' => 'tenant', 'email' => 'test@tenant.cz', 'password' => 'test']));

    // The current (tenant) broadcaster isn't the same as the central one
    expect($tenantBroadcaster->message)->not()->toBe($broadcaster->message);
    // Tenant broadcaster has the same channels as the central broadcaster
    expect($tenantBroadcaster->getChannels())->toEqualCanonicalizing($broadcaster->getChannels());
    // formatChannels prefixes the channel names now
    expect(invade($tenantBroadcaster)->formatChannels($channelNames))->toEqualCanonicalizing([
        'global__channel',
        $tenant->getTenantKey() . '.channel',
        'private-' . $tenant->getTenantKey() . '.user.' . $tenantUser->id,
    ]);

    // Initialize another tenant
    tenancy()->initialize($tenant2);

    auth()->login($tenantUser = User::create(['name' => 'tenant', 'email' => 'test2@tenant.cz', 'password' => 'test']));

    // formatChannels prefixes channels with the second tenant's key now
    expect(invade(app(BroadcastManager::class)->driver())->formatChannels($channelNames))->toEqualCanonicalizing([
        'global__channel',
        $tenant2->getTenantKey() . '.channel',
        'private-' . $tenant2->getTenantKey() . '.user.' . $tenantUser->id,
    ]);

    // The bootstrapper reverts to the tenant context – the channel names won't be prefixed anymore
    tenancy()->end();

    // The current broadcaster is the same as the central one again
    expect(app(BroadcastManager::class)->driver())->toBe($broadcaster);
    expect(invade(app(BroadcastManager::class)->driver())->formatChannels($channelNames))->toEqual($channelNames);
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
