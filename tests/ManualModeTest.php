<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\CreateTenantConnection;
use \Stancl\Tenancy\Tests\Etc\Tenant;

test('manual tenancy initialization works', function () {
    Event::listen(TenancyInitialized::class, CreateTenantConnection::class);

    $tenant = Tenant::create();

    expect(array_keys(config('database.connections')))->not()->toContain('tenant');

    tenancy()->initialize($tenant);
    
    expect(array_keys(config('database.connections')))->toContain('tenant');
});
