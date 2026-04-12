<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Database\Concerns\MaintenanceMode;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Events\TenantMaintenanceModeDisabled;
use Stancl\Tenancy\Events\TenantMaintenanceModeEnabled;
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

test('maintenance mode middleware can be used with universal routes', function () {
    Route::get('/foo', function () {
        return 'bar';
    })->middleware(['universal', InitializeTenancyByDomain::class, CheckTenantForMaintenanceMode::class]);

    $tenant = MaintenanceTenant::create();
    $tenant->domains()->create([
        'domain' => 'acme.localhost',
    ]);

    // Revert to central context after each request so that the tenant context
    // from the request doesn't persist
    $run = function (Closure $callback) { $callback(); tenancy()->end(); };

    $run(fn () => pest()->get('http://acme.localhost/foo')->assertStatus(200));
    $run(fn () => pest()->get('http://localhost/foo')->assertStatus(200));

    $tenant->putDownForMaintenance();

    $run(fn () => pest()->get('http://acme.localhost/foo')->assertStatus(503));
    $run(fn () => pest()->get('http://localhost/foo')->assertStatus(200)); // Not affected by a tenant's maintenance mode

    $tenant->bringUpFromMaintenance();

    $run(fn () => pest()->get('http://acme.localhost/foo')->assertStatus(200));
    $run(fn () => pest()->get('http://localhost/foo')->assertStatus(200));
});

test('maintenance mode events are fired', function () {
    $tenant = MaintenanceTenant::create();

    Event::fake([TenantMaintenanceModeEnabled::class, TenantMaintenanceModeDisabled::class]);

    $tenant->putDownForMaintenance();

    Event::assertDispatched(TenantMaintenanceModeEnabled::class);

    $tenant->bringUpFromMaintenance();

    Event::assertDispatched(TenantMaintenanceModeDisabled::class);
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
