<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Stancl\Tenancy\Database\Concerns\MaintenanceMode;
use Stancl\Tenancy\Middleware\CheckTenantForMaintenanceMode;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Tests\Etc\Tenant;

class MaintenanceModeTest extends TestCase
{
    #[Test]
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

        $this->expectException(HttpException::class);
        $this->withoutExceptionHandling()
            ->get('http://acme.localhost/foo');
    }
}

class MaintenanceTenant extends Tenant
{
    use MaintenanceMode;
}
