<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Features\UniversalRoutes;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Tests\Etc\Tenant;

class UniversalRouteTest extends TestCase
{
    public function tearDown(): void
    {
        InitializeTenancyByDomain::$onFail = null;

        parent::tearDown();
    }

    /** @test */
    public function a_route_can_work_in_both_central_and_tenant_context()
    {
        Route::middlewareGroup('universal', []);
        config(['tenancy.features' => [UniversalRoutes::class]]);

        Route::get('/foo', function () {
            return tenancy()->initialized
                ? 'Tenancy is initialized.'
                : 'Tenancy is not initialized.';
        })->middleware(['universal', InitializeTenancyByDomain::class]);

        $this->get('http://localhost/foo')
            ->assertSuccessful()
            ->assertSee('Tenancy is not initialized.');

        $tenant = Tenant::create([
            'id' => 'acme',
        ]);
        $tenant->domains()->create([
            'domain' => 'acme.localhost',
        ]);

        $this->get('http://acme.localhost/foo')
            ->assertSuccessful()
            ->assertSee('Tenancy is initialized.');
    }

    /** @test */
    public function making_one_route_universal_doesnt_make_all_routes_universal()
    {
        Route::get('/bar', function () {
            return tenant('id');
        })->middleware(InitializeTenancyByDomain::class);

        $this->a_route_can_work_in_both_central_and_tenant_context();
        tenancy()->end();

        $this->get('http://localhost/bar')
            ->assertStatus(500);

        $this->get('http://acme.localhost/bar')
            ->assertSuccessful()
            ->assertSee('acme');
    }
}
