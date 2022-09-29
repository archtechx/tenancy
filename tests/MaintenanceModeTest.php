<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Stancl\Tenancy\Database\Concerns\MaintenanceMode;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\CheckTenantForMaintenanceMode;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Tests\Etc\Tenant;

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

test('tenants can be put into maintenance mode using artisan commands', function() {
    Route::get('/foo', function () {
        return 'bar';
    })->middleware([InitializeTenancyByDomain::class, CheckTenantForMaintenanceMode::class]);

    $tenant = MaintenanceTenant::create();
    $tenant->domains()->create([
        'domain' => 'acme.localhost',
    ]);

    pest()->get('http://acme.localhost/foo')->assertStatus(200);

    Artisan::call('tenants:down');

    tenancy()->end(); // End tenancy before making a request
    pest()->get('http://acme.localhost/foo')->assertStatus(503);

    Artisan::call('tenants:up');

    tenancy()->end(); // End tenancy before making a request
    pest()->get('http://acme.localhost/foo')->assertStatus(200);
});

class MaintenanceTenant extends Tenant
{
    use MaintenanceMode;
}
