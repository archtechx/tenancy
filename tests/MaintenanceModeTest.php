<?php

declare(strict_types=1);

use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Foundation\Http\Exceptions\MaintenanceModeException;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Database\Concerns\MaintenanceMode;
use Stancl\Tenancy\Middleware\CheckTenantForMaintenanceMode;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

uses(Stancl\Tenancy\Tests\TestCase::class);

test('tenant can be in maintenance mode', function () {
    Route::get('/foo', function () {
        return 'bar';
    })->middleware([InitializeTenancyByDomain::class, CheckTenantForMaintenanceMode::class]);

    $tenant = MaintenanceTenant::create();
    $tenant->domains()->create([
        'domain' => 'acme.localhost',
    ]);

    $this->get('http://acme.localhost/foo')
        ->assertSuccessful();

    tenancy()->end(); // flush stored tenant instance

    $tenant->putDownForMaintenance();

    $this->expectException(HttpException::class);
    $this->withoutExceptionHandling()
        ->get('http://acme.localhost/foo');
});
