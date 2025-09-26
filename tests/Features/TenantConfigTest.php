<?php

declare(strict_types=1);

use Stancl\Tenancy\Bootstrappers\TenantConfigBootstrapper;
use Stancl\Tenancy\Tests\Etc\Tenant;
use function Stancl\Tenancy\Tests\pest;
use function Stancl\Tenancy\Tests\withBootstrapping;

beforeEach(function () {
    config([
        'tenancy.bootstrappers' => [TenantConfigBootstrapper::class],
    ]);

    withBootstrapping();
});

afterEach(function () {
    TenantConfigBootstrapper::$storageToConfigMap = [];
});

test('nested tenant values are merged', function () {
    expect(config('whitelabel.theme'))->toBeNull();

    TenantConfigBootstrapper::$storageToConfigMap =  [
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

    TenantConfigBootstrapper::$storageToConfigMap = [
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

    TenantConfigBootstrapper::$storageToConfigMap = [
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
