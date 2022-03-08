<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Database\Concerns\MaintenanceMode;
use Stancl\Tenancy\Middleware\CheckTenantForMaintenanceMode;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

        $this->expectException(HttpException::class);
        $this->withoutExceptionHandling()
            ->get('http://acme.localhost/foo');

        tenancy()->end();

        $tenant->bringUpFromMaintenance();

        tenancy()->end();

        $this->get('http://acme.localhost/foo')
            ->assertSuccessful()
            ->assertSeeText('bar');
    }

    /** @test */
    public function tenant_can_be_in_maintenance_mode_from_command()
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

        Artisan::call('tenancy:down');

        $this->expectException(HttpException::class);
        $this->withoutExceptionHandling()
            ->get('http://acme.localhost/foo');

        tenancy()->end();

        Artisan::call('tenancy:up');

        tenancy()->end();

        $this->get('http://acme.localhost/foo')
            ->assertSuccessful()
            ->assertSeeText('bar');
    }
}

class MaintenanceTenant extends Tenant
{
    use MaintenanceMode;
}
