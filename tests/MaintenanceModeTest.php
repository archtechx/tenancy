<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Foundation\Http\Exceptions\MaintenanceModeException;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Database\Concerns\MaintenanceMode;
use Stancl\Tenancy\Middleware\CheckTenantForMaintenanceMode;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Tests\Etc\Tenant;

class MaintenanceModeTest extends TestCase
{
    /** @test */
    public function tenant_can_be_in_maintenance_mode()
    {
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

        $this->expectException(MaintenanceModeException::class);
        $this->withoutExceptionHandling()
            ->get('http://acme.localhost/foo');

        $tenant->bringUpFromMaintenance();

        $this->withoutExceptionHandling()
            ->get('http://acme.localhost/foo')
            ->assertSeeText('bar');
    }
}

class MaintenanceTenant extends Tenant
{
    use MaintenanceMode;
}
