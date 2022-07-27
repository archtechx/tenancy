<?php

declare(strict_types=1);

use Stancl\Tenancy\Database\Concerns\MaintenanceMode;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\CheckTenantForMaintenanceMode;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Tests\Etc\Tenant;

test('tenant can be in maintenance mode', function () {
    Route::get('/foo', function () {
        return 'bar';
    })->middleware([InitializeTenancyByDomain::class, CheckTenantForMaintenanceMode::class]);

    $tenant = MaintenanceTenant::create();
    $tenant->domains()->create([
        'domain' => 'acme.localhost',
    ]);

    pest()->get('http://acme.localhost/foo')
        ->assertSuccessful();

    tenancy()->end(); // flush stored tenant instance

    $tenant->putDownForMaintenance();

    pest()->expectException(HttpException::class);
    pest()->withoutExceptionHandling()
        ->get('http://acme.localhost/foo');
});

class MaintenanceTenant extends Tenant
{
    use MaintenanceMode;
}
