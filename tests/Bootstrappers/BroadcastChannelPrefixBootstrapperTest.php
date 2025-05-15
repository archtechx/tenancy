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
