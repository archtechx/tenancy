<?php

declare(strict_types=1);

use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Bootstrappers\Integrations\ScoutPrefixBootstrapper;

beforeEach(function () {
    config(['tenancy.bootstrappers' => [ScoutPrefixBootstrapper::class]]);

    ScoutPrefixBootstrapper::$getTenantPrefixUsing = null;

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
});

afterEach(function () {
    ScoutPrefixBootstrapper::$getTenantPrefixUsing = null;
});

test('scout prefix is scoped to the tenant and reverted', function (string|null $originalPrefix) {
    config(['scout.prefix' => $originalPrefix]);

    $tenant1 = Tenant::create(['id' => '1']);
    $tenant2 = Tenant::create(['id' => '10']);

    tenancy()->initialize($tenant1);
    expect(config('scout.prefix'))->toBe($originalPrefix . '1_');

    tenancy()->initialize($tenant2);
    expect(config('scout.prefix'))->toBe($originalPrefix . '10_');

    tenancy()->end();
    expect(config('scout.prefix'))->toBe($originalPrefix);
})->with([
    'no original prefix' => [''],
    'null original prefix' => [null],
    'original prefix' => ['prod_'],
]);

test('getTenantPrefixUsing overrides the default tenant prefix', function () {
    config(['scout.prefix' => 'prod_']);

    ScoutPrefixBootstrapper::$getTenantPrefixUsing = fn (Tenant $tenant, string $originalPrefix) => 'custom-' . $tenant->getTenantKey() . '-' . $originalPrefix;

    tenancy()->initialize(Tenant::create(['id' => 'acme']));
    expect(config('scout.prefix'))->toBe('custom-acme-prod_');

    tenancy()->end();
    expect(config('scout.prefix'))->toBe('prod_');
});
