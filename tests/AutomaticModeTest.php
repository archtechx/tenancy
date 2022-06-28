<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Tests\Etc\Tenant;

uses(Stancl\Tenancy\Tests\TestCase::class);

beforeEach(function () {
    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
});

test('context is switched when tenancy is initialized', function () {
    config(['tenancy.bootstrappers' => [
        MyBootstrapper::class,
    ]]);

    $tenant = Tenant::create([
        'id' => 'acme',
    ]);

    tenancy()->initialize($tenant);

    $this->assertSame('acme', app('tenancy_initialized_for_tenant'));
});

test('context is reverted when tenancy is ended', function () {
    $this->context_is_switched_when_tenancy_is_initialized();

    tenancy()->end();

    $this->assertSame(true, app('tenancy_ended'));
});

test('context is switched when tenancy is reinitialized', function () {
    config(['tenancy.bootstrappers' => [
        MyBootstrapper::class,
    ]]);

    $tenant = Tenant::create([
        'id' => 'acme',
    ]);

    tenancy()->initialize($tenant);

    $this->assertSame('acme', app('tenancy_initialized_for_tenant'));

    $tenant2 = Tenant::create([
        'id' => 'foobar',
    ]);

    tenancy()->initialize($tenant2);

    $this->assertSame('foobar', app('tenancy_initialized_for_tenant'));
});

test('central helper runs callbacks in the central state', function () {
    tenancy()->initialize($tenant = Tenant::create());

    tenancy()->central(function () {
        $this->assertSame(null, tenant());
    });

    $this->assertSame($tenant, tenant());
});

test('central helper returns the value from the callback', function () {
    tenancy()->initialize(Tenant::create());

    $this->assertSame('foo', tenancy()->central(function () {
        return 'foo';
    }));
});

test('central helper reverts back to tenant context', function () {
    tenancy()->initialize($tenant = Tenant::create());

    tenancy()->central(function () {
        //
    });

    $this->assertSame($tenant, tenant());
});

test('central helper doesnt change tenancy state when called in central context', function () {
    $this->assertFalse(tenancy()->initialized);
    $this->assertNull(tenant());

    tenancy()->central(function () {
        //
    });

    $this->assertFalse(tenancy()->initialized);
    $this->assertNull(tenant());
});

// Helpers
function bootstrap(\Stancl\Tenancy\Contracts\Tenant $tenant)
{
    app()->instance('tenancy_initialized_for_tenant', $tenant->getTenantKey());
}

function revert()
{
    app()->instance('tenancy_ended', true);
}
