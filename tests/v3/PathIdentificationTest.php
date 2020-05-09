<?php

namespace Stancl\Tenancy\Tests\v3;

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Database\Models\Tenant;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedByPathException;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;
use Stancl\Tenancy\Tests\TestCase;

class PathIdentificationTest extends TestCase
{
    public function getEnvironmentSetup($app)
    {
        parent::getEnvironmentSetUp($app);

        config(['tenancy.global_middleware' => []]);
    }

    public function setUp(): void
    {
        parent::setUp();

        Route::group([
            'prefix' => '/{tenant}',
            'middleware' => InitializeTenancyByPath::class,
        ], function () {
            Route::get('/foo/{a}/{b}', function ($a, $b) {
                return "$a + $b";
            });

            Route::get('/bar', [TestController::class, 'index']);
        });
    }

    /** @test */
    public function tenant_can_be_identified_by_path()
    {
        Tenant::create([
            'id' => 'acme',
        ]);

        $this->assertFalse(tenancy()->initialized);

        $this
            ->get('/acme/foo/abc/xyz');

        $this->assertTrue(tenancy()->initialized);
        $this->assertSame('acme', tenant('id'));
    }

    /** @test */
    public function route_actions_dont_get_the_tenant_id()
    {
        Tenant::create([
            'id' => 'acme',
        ]);

        $this->assertFalse(tenancy()->initialized);

        $this
            ->get('/acme/foo/abc/xyz')
            ->assertSee('abc + xyz');

        $this->assertTrue(tenancy()->initialized);
        $this->assertSame('acme', tenant('id'));
    }

    /** @test */
    public function exception_is_thrown_when_tenant_cannot_be_identified_by_path()
    {
        // todo the exception assertion doesn't work
        $this->expectException(TenantCouldNotBeIdentifiedByPathException::class);
         
        $this->assertFalse(tenancy()->initialized);
    }
}
