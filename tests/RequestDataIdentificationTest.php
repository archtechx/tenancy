<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;
use Stancl\Tenancy\Tenant;

class RequestDataIdentificationTest extends TestCase
{
    public $autoCreateTenant = false;
    public $autoInitTenancy = false;

    public function setUp(): void
    {
        parent::setUp();

        config([
            'tenancy.exempt_domains' => [
                'localhost',
            ],
        ]);

        Route::middleware(InitializeTenancyByRequestData::class)->get('/test', function () {
            return 'Tenant id: ' . tenant('id');
        });
    }

    /** @test */
    public function header_identification_works()
    {
        $this->app->bind(InitializeTenancyByRequestData::class, function () {
            return new InitializeTenancyByRequestData('X-Tenant');
        });
        $tenant = Tenant::new()->save();
        $tenant2 = Tenant::new()->save();

        $this
            ->withoutExceptionHandling()
            ->get('test', [
                'X-Tenant' => $tenant->id,
            ])
            ->assertSee($tenant->id);
    }

    /** @test */
    public function query_parameter_identification_works()
    {
        $this->app->bind(InitializeTenancyByRequestData::class, function () {
            return new InitializeTenancyByRequestData(null, 'tenant');
        });
        $tenant = Tenant::new()->save();
        $tenant2 = Tenant::new()->save();

        $this
            ->withoutExceptionHandling()
            ->get('test?tenant=' . $tenant->id)
            ->assertSee($tenant->id);
    }
}
