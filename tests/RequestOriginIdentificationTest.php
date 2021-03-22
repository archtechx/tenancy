<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestOrigin;
use Stancl\Tenancy\Tests\Etc\Tenant;

class RequestOriginIdentificationTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        config([
            'tenancy.central_domains' => [
                'localhost',
            ],
        ]);

        Route::middleware(InitializeTenancyByRequestOrigin::class)->get('/test', function () {
            return 'Tenant id: ' . tenant('id');
        });
    }

    /** @test */
    public function origin_identification_works()
    {
        $tenant = Tenant::create();
	    $tenant->domains()->create([
		    'domain' => 'localhost'
	    ]);

        $this
            ->withoutExceptionHandling()
            ->get('test', [
                'Origin' => 'http://localhost',
            ])
            ->assertSee($tenant->id);
    }
}
