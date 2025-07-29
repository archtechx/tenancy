<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Bootstrappers\LogTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;

beforeEach(function () {
    config([
        'tenancy.bootstrappers' => [
            // FilesystemTenancyBootstrapper needed for storage path channels (added in tests that check the storage path channel logic)
            LogTenancyBootstrapper::class,
        ],
    ]);

    // Reset static properties
    LogTenancyBootstrapper::$channelOverrides = [];
    LogTenancyBootstrapper::$storagePathChannels = ['single', 'daily'];

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
});

afterEach(function () {
    LogTenancyBootstrapper::$channelOverrides = [];
    LogTenancyBootstrapper::$storagePathChannels = ['single', 'daily'];
});

test('storage path channels get tenant-specific paths', function () {
    // Note that for LogTenancyBootstrapper to change the paths correctly,
    // the bootstrapper MUST run after FilesystemTenancyBootstrapper.
    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
            LogTenancyBootstrapper::class,
        ],
    ]);

    $tenant = Tenant::create();

    // Storage path channels are 'single' and 'daily' by default.
    // This can be customized via LogTenancyBootstrapper::$storagePathChannels.
    foreach (LogTenancyBootstrapper::$storagePathChannels as $channel) {
        config(['logging.default' => $channel]);

        $originalPath = config("logging.channels.{$channel}.path");

        tenancy()->initialize($tenant);

        // Path should now point to the log in the tenant's storage directory
        $tenantLogPath = "storage/tenant{$tenant->id}/logs/laravel.log";
        expect(config("logging.channels.{$channel}.path"))
            ->not()->toBe($originalPath)
            ->toEndWith($tenantLogPath);

        tenancy()->end();

        // Path should be reverted
        expect(config("logging.channels.{$channel}.path"))->toBe($originalPath);
    }
});

test('all channels included in the log stack get processed', function () {
    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
            LogTenancyBootstrapper::class,
        ],
        'logging.default' => 'stack',
        'logging.channels.stack' => [
            'driver' => 'stack',
            'channels' => ['single', 'daily'],
        ],
    ]);

    $originalSinglePath = config('logging.channels.single.path');
    $originalDailyPath = config('logging.channels.daily.path');

    $tenant = Tenant::create();

    tenancy()->initialize($tenant);

    // Both channels in the stack should be updated
    expect(config('logging.channels.single.path'))->not()->toBe($originalSinglePath);
    expect(config('logging.channels.daily.path'))->not()->toBe($originalDailyPath);

    tenancy()->end();

    expect(config('logging.channels.single.path'))->toBe($originalSinglePath);
    expect(config('logging.channels.daily.path'))->toBe($originalDailyPath);
});

test('channel overrides work correctly with both arrays and closures', function () {
    config([
        'logging.default' => 'slack',
        'logging.channels.slack' => [
            'driver' => 'slack',
            'url' => $originalSlackUrl = 'https://default-webhook.example.com',
            'username' => 'Default',
        ],
    ]);

    $tenant = Tenant::create(['id' => 'tenant1', 'webhookUrl' => 'https://tenant-webhook.example.com']);

    // Specify channel override for 'slack' channel using an array
    LogTenancyBootstrapper::$channelOverrides = [
        'slack' => [
            'url' => 'webhookUrl', // $tenant->webhookUrl will be used
        ],
    ];

    tenancy()->initialize($tenant);

    expect(config('logging.channels.slack.url'))->toBe($tenant->webhookUrl);
    expect(config('logging.channels.slack.username'))->toBe('Default'); // Default username -- remains default unless specified

    tenancy()->end();

    // After tenancy ends, the original config should be restored
    expect(config('logging.channels.slack.url'))->toBe($originalSlackUrl);

    // Now, use closure to set the slack username to $tenant->id (tenant1)
    LogTenancyBootstrapper::$channelOverrides['slack'] = function ($config, $tenant) {
        $config->set('logging.channels.slack.username', $tenant->id);
    };

    tenancy()->initialize($tenant);

    expect(config('logging.channels.slack.url'))->toBe($originalSlackUrl); // Unchanged
    expect(config('logging.channels.slack.username'))->toBe($tenant->id);

    tenancy()->end();

    // Config reverted back to original
    expect(config('logging.channels.slack.username'))->toBe('Default');
});

test('channel overrides take precedence over the default storage path channel updating logic', function () {
    config(['logging.default' => 'single']);

    $tenant = Tenant::create(['id' => 'tenant1']);

    LogTenancyBootstrapper::$channelOverrides = [
        'single' => function ($config, $tenant) {
            $config->set('logging.channels.single.path', storage_path("logs/override-{$tenant->id}.log"));
        },
    ];

    tenancy()->initialize($tenant);

    // Should use override, not the default storage path updating behavior
    expect(config('logging.channels.single.path'))->toEndWith('storage/logs/override-tenant1.log');
});

test('multiple channel overrides work together', function () {
    config([
        'logging.default' => 'stack',
        'logging.channels.stack' => [
            'driver' => 'stack',
            'channels' => ['slack', 'single'],
        ],
    ]);

    $originalSinglePath = config('logging.channels.single.path');
    $originalSlackUrl = config('logging.channels.slack.url');

    $tenant = Tenant::create(['id' => 'tenant1', 'slackUrl' => 'https://tenant-slack.example.com']);

    LogTenancyBootstrapper::$channelOverrides = [
        'slack' => ['url' => 'slackUrl'],
        'single' => function ($config, $tenant) {
            $config->set('logging.channels.single.path', storage_path("logs/override-{$tenant->id}.log"));
        },
    ];

    tenancy()->initialize($tenant);

    expect(config('logging.channels.slack.url'))->toBe('https://tenant-slack.example.com');
    expect(config('logging.channels.single.path'))->toEndWith('storage/logs/override-tenant1.log');

    tenancy()->end();

    expect(config('logging.channels.slack.url'))->toBe($originalSlackUrl);
    expect(config('logging.channels.single.path'))->toBe($originalSinglePath);
});

test('channels are forgotten and re-resolved during bootstrap and revert', function () {
    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
            LogTenancyBootstrapper::class,
        ],
        'logging.default' => 'single'
    ]);

    $logManager = app('log');
    $originalChannel = $logManager->channel('single');
    $originalSinglePath = config('logging.channels.single.path');

    $tenant = Tenant::create();

    tenancy()->initialize($tenant);

    // After bootstrap, the channel should be a new instance with the updated config
    $tenantChannel = $logManager->channel('single');
    $tenantSingleChannelPath = $tenantChannel->getLogger()->getHandlers()[0]->getUrl();

    expect($tenantChannel)->not()->toBe($originalChannel);
    expect($tenantSingleChannelPath)
        ->not()->toBe($originalSinglePath)
        ->toEndWith("storage/tenant{$tenant->id}/logs/laravel.log");

    tenancy()->end();

    // After revert, the channel should get re-resolved with the original config
    $currentChannel = $logManager->channel('single');
    $currentChannelPath = $currentChannel->getLogger()->getHandlers()[0]->getUrl();

    expect($currentChannel)->not()->toBe($tenantChannel);
    expect($currentChannelPath)->toBe($originalSinglePath);
});
