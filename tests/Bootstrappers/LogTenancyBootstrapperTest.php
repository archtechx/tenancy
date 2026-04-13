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
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config([
        'tenancy.bootstrappers' => [
            // FilesystemTenancyBootstrapper needed for LogTenancyBootstrapper to work with storage path channels BY DEFAULT (note that this can be completely overridden)
            LogTenancyBootstrapper::class,
        ],
    ]);

    $logFiles = array_merge(
        glob(storage_path('logs/*.log')) ?: [],
        glob(storage_path('tenant*/logs/*.log')) ?: []
    );

    foreach ($logFiles as $path) {
        @unlink($path);
    }

    // Reset static properties
    LogTenancyBootstrapper::$channelOverrides = [];
    LogTenancyBootstrapper::$storagePathChannels = ['single', 'daily'];

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
});

afterEach(function () {
    LogTenancyBootstrapper::$channelOverrides = [];
    LogTenancyBootstrapper::$storagePathChannels = ['single', 'daily'];

    $logFiles = array_merge(
        glob(storage_path('logs/*.log')) ?: [],
        glob(storage_path('tenant*/logs/*.log')) ?: []
    );

    foreach ($logFiles as $path) {
        @unlink($path);
    }
});

test('storage path channels get tenant-specific paths by default', function () {
    // Note that for LogTenancyBootstrapper to change the paths correctly by default,
    // the bootstrapper MUST run after FilesystemTenancyBootstrapper.
    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
            LogTenancyBootstrapper::class,
        ],
    ]);

    $centralStoragePath = storage_path();
    $tenant = Tenant::create();

    // Storage path channels are 'single' and 'daily' by default.
    // This can be customized via LogTenancyBootstrapper::$storagePathChannels.
    foreach (LogTenancyBootstrapper::$storagePathChannels as $channel) {
        $originalPath = config("logging.channels.{$channel}.path");

        tenancy()->initialize($tenant);

        // Path should now point to the log in the tenant's storage directory
        $tenantLogPath = "{$centralStoragePath}/tenant{$tenant->id}/logs/laravel.log";
        expect(config("logging.channels.{$channel}.path"))
            ->not()->toBe($originalPath)
            ->toBe($tenantLogPath);

        tenancy()->end();

        // Path should be reverted
        expect(config("logging.channels.{$channel}.path"))->toBe($originalPath);
    }
});

test('all channels included in the log stack get processed correctly', function () {
    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
            LogTenancyBootstrapper::class,
        ],
        'logging.channels.stack' => [
            'driver' => 'stack',
            'channels' => ['single', 'daily'],
        ],
    ]);

    $centralStoragePath = storage_path();
    $centralLogPath = $centralStoragePath . '/logs/laravel.log';
    $originalSinglePath = config('logging.channels.single.path');
    $originalDailyPath = config('logging.channels.daily.path');

    // By default, both paths are the same in the config.
    // Note that in actual usage, the daily log file name is parsed differently from the path in the config,
    // but the paths *in the config* are the same.
    expect($centralLogPath)
        ->toBe($centralStoragePath . '/logs/laravel.log')
        ->toBe($originalSinglePath)
        ->toBe($originalDailyPath);

    $tenant = Tenant::create();

    tenancy()->initialize($tenant);

    // Both channels in the stack are updated correctly
    expect("{$centralStoragePath}/tenant{$tenant->id}/logs/laravel.log")
        ->not()->toBe($originalSinglePath)
        ->not()->toBe($originalDailyPath)
        ->toBe(config('logging.channels.single.path'))
        ->toBe(config('logging.channels.daily.path'));

    tenancy()->end();

    expect(config('logging.channels.single.path'))->toBe($originalSinglePath);
    expect(config('logging.channels.daily.path'))->toBe($originalDailyPath);
});

test('channel overrides work correctly with both arrays and closures', function () {
    config([
        'logging.channels.stack.channels' => ['slack', 'single'],
        'logging.channels.slack' => [
            'url' => $originalSlackUrl = 'default-webhook',
            'username' => 'Default',
        ],
    ]);

    $centralStoragePath = storage_path();
    $originalSinglePath = config('logging.channels.single.path');

    $tenant = Tenant::create(['webhookUrl' => 'tenant-webhook']);

    // Test both array mapping and closure-based overrides
    LogTenancyBootstrapper::$channelOverrides = [
        'slack' => ['url' => 'webhookUrl'], // slack.url will be mapped to $tenant->webhookUrl
        'single' => function (Tenant $tenant, array $channel) use ($centralStoragePath) {
            return array_merge($channel, ['path' => $centralStoragePath . "/logs/override-{$tenant->id}.log"]);
        },
    ];

    tenancy()->initialize($tenant);

    // Array mapping overrides work
    expect(config('logging.channels.slack.url'))->toBe($tenant->webhookUrl);
    expect(config('logging.channels.slack.username'))->toBe('Default'); // Default username, remains default unless overridden

    // Closure overrides work
    expect(config('logging.channels.single.path'))->toBe("{$centralStoragePath}/logs/override-{$tenant->id}.log");

    tenancy()->end();

    // After tenancy ends, the original config should be restored
    expect(config('logging.channels.slack.url'))->toBe($originalSlackUrl);
    expect(config('logging.channels.single.path'))->toBe($originalSinglePath);
    expect(config('logging.channels.slack.username'))->toBe('Default'); // Not changed at all
});

test('channel config keys remain unchanged if the specified tenant override attribute is null', function() {
    config(['logging.channels.slack.username' => 'Default username']);

    LogTenancyBootstrapper::$channelOverrides = [
        'slack' => ['username' => 'nonExistentAttribute'], // $tenant->nonExistentAttribute
    ];

    tenancy()->initialize(Tenant::create());

    // The username should remain unchanged since the tenant attribute is null
    expect(config('logging.channels.slack.username'))->toBe('Default username');
});

test('channel overrides take precedence over the default storage path channel updating logic', function () {
    $tenant = Tenant::create(['id' => 'tenant1']);

    LogTenancyBootstrapper::$channelOverrides = [
        'single' => function (Tenant $tenant, array $channel) {
            return array_merge($channel, ['path' => storage_path("logs/override-{$tenant->id}.log")]);
        },
    ];

    tenancy()->initialize($tenant);

    // Should use override, not the default storage path updating behavior
    expect(config('logging.channels.single.path'))->toEndWith('storage/logs/override-tenant1.log');
});

test('channels are forgotten and re-resolved during bootstrap and revert', function () {
    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
            LogTenancyBootstrapper::class,
        ],
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

// Test real usage
test('logs are written to tenant-specific files and do not leak between contexts', function () {
    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
            LogTenancyBootstrapper::class,
        ],
    ]);

    $centralLogPath = storage_path('logs/laravel.log');

    Log::channel('single')->info('central');

    expect(file_get_contents($centralLogPath))->toContain('central');

    [$tenant1, $tenant2] = [Tenant::create(['id' => 'tenant1']), Tenant::create(['id' => 'tenant2'])];

    tenancy()->runForMultiple([$tenant1, $tenant2], function (Tenant $tenant) use ($centralLogPath) {
        Log::channel('single')->info($tenant->id);

        $tenantLogPath = storage_path('logs/laravel.log');

        // The log gets saved to the tenant's storage directory (default behavior)
        expect($tenantLogPath)
            ->not()->toBe($centralLogPath)
            ->toEndWith("storage/tenant{$tenant->id}/logs/laravel.log");

        expect(file_get_contents($tenantLogPath))
            ->toContain($tenant->id)
            ->not()->toContain('central');
    });

    // Tenant log messages didn't leak into central log
    expect(file_get_contents($centralLogPath))
        ->toContain('central')
        ->not()->toContain('tenant1')
        ->not()->toContain('tenant2');

    // Tenant log messages didn't leak to logs of other tenants
    tenancy()->initialize($tenant1);

    expect(file_get_contents(storage_path('logs/laravel.log')))
        ->toContain('tenant1')
        ->not()->toContain('central')
        ->not()->toContain('tenant2');

    tenancy()->initialize($tenant2);

    expect(file_get_contents(storage_path('logs/laravel.log')))
        ->toContain('tenant2')
        ->not()->toContain('central')
        ->not()->toContain('tenant1');

    // Overriding the channels also works
    // Channel overrides also override the default behavior for the storage path-based channels
    $tenant = Tenant::create(['id' => 'override-tenant']);

    LogTenancyBootstrapper::$channelOverrides = [
        'single' => function (Tenant $tenant, array $channel) {
            // The tenant log path will be set to storage/tenantoverride-tenant/logs/custom-override-tenant.log
            return array_merge($channel, ['path' => storage_path("logs/custom-{$tenant->id}.log")]);
        },
    ];

    // Tenant context log (should use custom path due to override)
    tenancy()->initialize($tenant);

    Log::channel('single')->info('tenant-override');

    expect(file_get_contents(storage_path('logs/custom-override-tenant.log')))->toContain('tenant-override');
});

test('stack logs are written to all configured channels with tenant-specific paths', function () {
    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
            LogTenancyBootstrapper::class,
        ],
        'logging.channels.stack' => [
            'driver' => 'stack',
            'channels' => ['single', 'daily'],
        ],
    ]);

    $tenant = Tenant::create(['id' => 'stack-tenant']);
    $today = now()->format('Y-m-d');

    // Central context stack log
    Log::channel('stack')->info('central');
    $centralSingleLogPath = storage_path('logs/laravel.log');

    // The single and daily channels have the same path in the config, but the daily driver parses the file name so that the date is included in the file name
    $centralDailyLogPath = storage_path("logs/laravel-{$today}.log");

    expect(file_get_contents($centralSingleLogPath))->toContain('central');
    expect(file_get_contents($centralDailyLogPath))->toContain('central');

    // Tenant context stack log
    tenancy()->initialize($tenant);
    Log::channel('stack')->info('tenant');
    $tenantSingleLogPath = storage_path('logs/laravel.log');
    $tenantDailyLogPath = storage_path("logs/laravel-{$today}.log");

    expect(file_get_contents($tenantSingleLogPath))->toContain('tenant');
    expect(file_get_contents($tenantDailyLogPath))->toContain('tenant');

    // Verify tenant logs don't contain central messages
    expect(file_get_contents($tenantSingleLogPath))->not()->toContain('central');
    expect(file_get_contents($tenantDailyLogPath))->not()->toContain('central');

    tenancy()->end();

    // Verify central logs still only contain the central messages
    expect(file_get_contents($centralSingleLogPath))
        ->toContain('central')
        ->not()->toContain('tenant');

    expect(file_get_contents($centralDailyLogPath))
        ->toContain('central')
        ->not()->toContain('tenant');
});

test('slack channel uses correct webhook urls', function () {
    config([
        'logging.channels.slack.url' => 'central-webhook',
        'logging.channels.slack.level' => 'debug', // Set level to debug to keep the tests simple, since the default level here is 'critical'
    ]);

    $assertWebhook = function (string $expectedWebhook, string $message): void {
        $thrown = false;

        // Because the Slack channel uses cURL to send messages, we cannot use Http::fake() here.
        // Instead, we catch the exception and check the error message which contains the actual webhook URL
        // (logging always throws "Curl error (code 6): Could not resolve host: {webhook_url}").
        try {
            Log::channel('slack')->info($message);
        } catch (Exception $e) {
            $thrown = true;
            expect($e->getMessage())->toContain($expectedWebhook);
        }

        expect($thrown)->toBeTrue();
    };

    $tenant1 = Tenant::create(['id' => 'tenant1', 'logging' => ['slackUrl' => 'tenant1-webhook']]);
    $tenant2 = Tenant::create(['id' => 'tenant2', 'logging' => ['slackUrl' => 'tenant2-webhook']]);

    // Attribute mapping using nested attributes (dot notation) works
    LogTenancyBootstrapper::$channelOverrides = [
        'slack' => ['url' => 'logging.slackUrl'],
    ];

    // Test central context - should attempt to use central webhook
    $assertWebhook('central-webhook', 'central');

    // Slack channel should attempt to use the tenant-specific webhooks
    tenancy()->runForMultiple([$tenant1, $tenant2], function (Tenant $tenant) use ($assertWebhook) {
        $assertWebhook($tenant->logging['slackUrl'], $tenant->id);
    });

    // Central context, central webhook should be used again
    $assertWebhook('central-webhook', 'central');
});
