<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Database\Models\Tenant;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;
use Stancl\Tenancy\Tests\TestCase;

class RequestDataIdentificationTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        config([
            'tenancy.central_domains' => [
                'localhost',
            ],
        ]);

        InitializeTenancyByRequestData::$header = 'X-Tenant';
        InitializeTenancyByRequestData::$queryParameter = 'tenant';

        Route::middleware(InitializeTenancyByRequestData::class)->get('/test', function () {
            return 'Tenant id: ' . tenant('id');
        });
    }

    /** @test */
    public function header_identification_works()
    {
        InitializeTenancyByRequestData::$header = 'X-Tenant';
        $tenant = Tenant::create();
        $tenant2 = Tenant::create();

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
        InitializeTenancyByRequestData::$header = null;
        InitializeTenancyByRequestData::$queryParameter = 'tenant';

        $tenant = Tenant::create();
        $tenant2 = Tenant::create();

        $this
            ->withoutExceptionHandling()
            ->get('test?tenant=' . $tenant->id)
            ->assertSee($tenant->id);
    }
}
