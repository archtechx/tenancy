<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Tests\Etc\Tenant;
use function Stancl\Tenancy\Tests\pest;
use function Stancl\Tenancy\Tests\withTenantDatabases;

beforeEach(function () {
    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
});

test('context is switched to tenant when initializing tenancy and reverted when ending tenancy', function () {
    config(['tenancy.bootstrappers' => [
        MyBootstrapper::class,
    ]]);

    $tenant = Tenant::create([
        'id' => 'acme',
    ]);

    tenancy()->initialize($tenant);

    expect(app('tenancy_initialized_for_tenant'))->toBe('acme');

    tenancy()->end();

    expect(app('tenancy_ended'))->toBe(true);
});

test('context is switched when tenancy is reinitialized', function () {
    config(['tenancy.bootstrappers' => [
        MyBootstrapper::class,
    ]]);

    $tenant = Tenant::create([
        'id' => 'acme',
    ]);

    tenancy()->initialize($tenant);

    expect(app('tenancy_initialized_for_tenant'))->toBe('acme');

    $tenant2 = Tenant::create([
        'id' => 'foobar',
    ]);

    tenancy()->initialize($tenant2);

    expect(app('tenancy_initialized_for_tenant'))->toBe('foobar');
});

test('central helper runs callbacks in the central state', function () {
    withTenantDatabases();

    tenancy()->initialize($tenant = Tenant::create());

    tenancy()->central(function () {
        expect(tenant())->toBe(null);
    });

    expect(tenant())->toBe($tenant);
});

test('central helper returns the value from the callback', function () {
    withTenantDatabases();

    tenancy()->initialize(Tenant::create());

    pest()->assertSame('foo', tenancy()->central(function () {
        return 'foo';
    }));
});

test('central helper reverts back to tenant context', function () {
    withTenantDatabases();

    tenancy()->initialize($tenant = Tenant::create());

    tenancy()->central(function () {
        //
    });

    expect(tenant())->toBe($tenant);
});

test('central helper doesnt change tenancy state when called in central context', function () {
    expect(tenancy()->initialized)->toBeFalse();
    expect(tenant())->toBeNull();

    tenancy()->central(function () {
        //
    });

    expect(tenancy()->initialized)->toBeFalse();
    expect(tenant())->toBeNull();
});

class MyBootstrapper implements TenancyBootstrapper
{
    public function bootstrap(\Stancl\Tenancy\Contracts\Tenant $tenant): void
    {
        app()->instance('tenancy_initialized_for_tenant', $tenant->getTenantKey());
    }

    public function revert(): void
    {
        app()->instance('tenancy_ended', true);
    }
}
