<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Database\Concerns\MaintenanceMode;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\CheckTenantForMaintenanceMode;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use function Stancl\Tenancy\Tests\pest;
use Stancl\Tenancy\Tests\Etc\Tenant;

beforeEach(function () {
    config(['tenancy.models.tenant' => MaintenanceTenant::class]);
});

test('tenants can be in maintenance mode', function () {
    Route::get('/foo', function () {
        return 'bar';
    })->middleware([InitializeTenancyByDomain::class, CheckTenantForMaintenanceMode::class]);

    $tenant = MaintenanceTenant::create();
    $tenant->domains()->create([
        'domain' => 'acme.localhost',
    ]);

    pest()->get('http://acme.localhost/foo')->assertStatus(200);

    $tenant->putDownForMaintenance();

    tenancy()->end(); // End tenancy before making a request
    pest()->get('http://acme.localhost/foo')->assertStatus(503);

    $tenant->bringUpFromMaintenance();

    tenancy()->end(); // End tenancy before making a request
    pest()->get('http://acme.localhost/foo')->assertStatus(200);
});

test('maintenance mode events are fired', function () {
    $tenant = MaintenanceTenant::create();

    Event::fake();

    $tenant->putDownForMaintenance();

    Event::assertDispatched(\Stancl\Tenancy\Events\TenantMaintenanceModeEnabled::class);

    $tenant->bringUpFromMaintenance();

    Event::assertDispatched(\Stancl\Tenancy\Events\TenantMaintenanceModeDisabled::class);
});

test('tenants can be put into maintenance mode using artisan commands', function() {
    Route::get('/foo', function () {
        return 'bar';
    })->middleware([InitializeTenancyByDomain::class, CheckTenantForMaintenanceMode::class]);

    $tenant = MaintenanceTenant::create();
    $tenant->domains()->create([
        'domain' => 'acme.localhost',
    ]);

    pest()->get('http://acme.localhost/foo')->assertStatus(200);

    pest()->artisan('tenants:down')
        ->expectsOutputToContain('Tenants are now in maintenance mode.')
        ->assertExitCode(0);

    Artisan::call('tenants:down');

    tenancy()->end(); // End tenancy before making a request
    pest()->get('http://acme.localhost/foo')->assertStatus(503);

    pest()->artisan('tenants:up')
        ->expectsOutputToContain('Tenants are now out of maintenance mode.')
        ->assertExitCode(0);

    tenancy()->end(); // End tenancy before making a request
    pest()->get('http://acme.localhost/foo')->assertStatus(200);
});

class MaintenanceTenant extends Tenant
{
    use MaintenanceMode;
}
