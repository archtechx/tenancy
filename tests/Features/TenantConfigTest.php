<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Features\TenantConfig;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Tests\Etc\Tenant;

afterEach(function () {
    TenantConfig::$storageToConfigMap = [];
});

test('nested tenant values are merged', function () {
    expect(config('whitelabel.theme'))->toBeNull();
    config([
        'tenancy.features' => [TenantConfig::class],
        'tenancy.bootstrappers' => [],
    ]);
    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);

    TenantConfig::$storageToConfigMap =  [
        'whitelabel.config.theme' => 'whitelabel.theme',
    ];

    $tenant = Tenant::create([
        'whitelabel' => ['config' => ['theme' => 'dark']],
    ]);

    tenancy()->initialize($tenant);
    expect(config('whitelabel.theme'))->toBe('dark');
    tenancy()->end();
});

test('config is merged and removed', function () {
    expect(config('services.paypal'))->toBe(null);
    config([
        'tenancy.features' => [TenantConfig::class],
        'tenancy.bootstrappers' => [],
    ]);
    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);

    TenantConfig::$storageToConfigMap = [
        'paypal_api_public' => 'services.paypal.public',
        'paypal_api_private' => 'services.paypal.private',
    ];

    $tenant = Tenant::create([
        'paypal_api_public' => 'foo',
        'paypal_api_private' => 'bar',
    ]);

    tenancy()->initialize($tenant);
    expect(config('services.paypal'))->toBe(['public' => 'foo', 'private' => 'bar']);

    tenancy()->end();
    pest()->assertSame([
        'public' => null,
        'private' => null,
    ], config('services.paypal'));
});

test('the value can be set to multiple config keys', function () {
    expect(config('services.paypal'))->toBe(null);
    config([
        'tenancy.features' => [TenantConfig::class],
        'tenancy.bootstrappers' => [],
    ]);
    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);

    TenantConfig::$storageToConfigMap = [
        'paypal_api_public' => [
            'services.paypal.public1',
            'services.paypal.public2',
        ],
        'paypal_api_private' => 'services.paypal.private',
    ];

    $tenant = Tenant::create([
        'paypal_api_public' => 'foo',
        'paypal_api_private' => 'bar',
    ]);

    tenancy()->initialize($tenant);
    pest()->assertSame([
        'public1' => 'foo',
        'public2' => 'foo',
        'private' => 'bar',
    ], config('services.paypal'));

    tenancy()->end();
    pest()->assertSame([
        'public1' => null,
        'public2' => null,
        'private' => null,
    ], config('services.paypal'));
});
