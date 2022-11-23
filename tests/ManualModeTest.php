<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Listeners\CreateTenantConnection;
use Stancl\Tenancy\Listeners\UseCentralConnection;
use Stancl\Tenancy\Listeners\UseTenantConnection;
use \Stancl\Tenancy\Tests\Etc\Tenant;

test('manual tenancy initialization works', function () {
    Event::listen(TenancyInitialized::class, CreateTenantConnection::class);
    Event::listen(TenancyInitialized::class, UseTenantConnection::class);
    Event::listen(TenancyEnded::class, UseCentralConnection::class);

    $tenant = Tenant::create();

    expect(array_keys(app('db')->getConnections()))->toBe(['central']);
    pest()->assertArrayNotHasKey('tenant', config('database.connections'));

    tenancy()->initialize($tenant);

    expect(app('db')->getDefaultConnection())->toBe('tenant');
    expect(array_keys(app('db')->getConnections()))->toBe(['central', 'tenant']);
    pest()->assertArrayHasKey('tenant', config('database.connections'));

    tenancy()->end();

    expect(array_keys(app('db')->getConnections()))->toBe(['central']);
    expect(config('database.connections.tenant'))->toBeNull();
    expect(app('db')->getDefaultConnection())->toBe(config('tenancy.database.central_connection'));
});
