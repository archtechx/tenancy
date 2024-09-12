<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenantGoingInMaintenanceMode;
use Stancl\Tenancy\Events\TenantWentInMaintenanceMode;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Foundation\Http\Exceptions\MaintenanceModeException;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Database\Concerns\MaintenanceMode;
use Stancl\Tenancy\Middleware\CheckTenantForMaintenanceMode;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class MaintenanceModeTest extends TestCase
{
    /** @test */
    public function tenant_can_be_in_maintenance_mode()
    {
        Event::fake([TenantGoingInMaintenanceMode::class, TenantWentInMaintenanceMode::class]);

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

        Event::assertNotDispatched(TenantGoingInMaintenanceMode::class);
        Event::assertNotDispatched(TenantWentInMaintenanceMode::class);

        $tenant->putDownForMaintenance();

        Event::assertDispatched(TenantGoingInMaintenanceMode::class);
        Event::assertDispatched(TenantWentInMaintenanceMode::class);

        $this->expectException(HttpException::class);
        $this->withoutExceptionHandling()
            ->get('http://acme.localhost/foo');
    }
}

class MaintenanceTenant extends Tenant
{
    use MaintenanceMode;
}
