<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Exceptions\RouteIsMissingTenantParameterException;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedByPathException;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;
use Stancl\Tenancy\Resolvers\PathTenantResolver;
use Stancl\Tenancy\Tests\Etc\Tenant;

class PathIdentificationTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        PathTenantResolver::$tenantParameterName = 'tenant';

        Route::group([
            'prefix' => '/{tenant}',
            'middleware' => InitializeTenancyByPath::class,
        ], function () {
            Route::get('/foo/{a}/{b}', function ($a, $b) {
                return "$a + $b";
            });
        });
    }

    public function tearDown(): void
    {
        parent::tearDown();

        // Global state cleanup
        PathTenantResolver::$tenantParameterName = 'tenant';
    }

    /** @test */
    public function tenant_can_be_identified_by_path()
    {
        Tenant::create([
            'id' => 'acme',
        ]);

        $this->assertFalse(tenancy()->initialized);

        $this->get('/acme/foo/abc/xyz');

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
            ->assertContent('abc + xyz');

        $this->assertTrue(tenancy()->initialized);
        $this->assertSame('acme', tenant('id'));
    }

    /** @test */
    public function exception_is_thrown_when_tenant_cannot_be_identified_by_path()
    {
        $this->expectException(TenantCouldNotBeIdentifiedByPathException::class);

        $this
            ->withoutExceptionHandling()
            ->get('/acme/foo/abc/xyz');

        $this->assertFalse(tenancy()->initialized);
    }

    /** @test */
    public function onfail_logic_can_be_customized()
    {
        InitializeTenancyByPath::$onFail = function () {
            return 'foo';
        };

        $this
            ->get('/acme/foo/abc/xyz')
            ->assertContent('foo');
    }

    /** @test */
    public function an_exception_is_thrown_when_the_routes_first_parameter_is_not_tenant()
    {
        Route::group([
            // 'prefix' => '/{tenant}', -- intentionally commented
            'middleware' => InitializeTenancyByPath::class,
        ], function () {
            Route::get('/bar/{a}/{b}', function ($a, $b) {
                return "$a + $b";
            });
        });

        Tenant::create([
            'id' => 'acme',
        ]);

        $this->expectException(RouteIsMissingTenantParameterException::class);

        $this
            ->withoutExceptionHandling()
            ->get('/bar/foo/bar');
    }

    /** @test */
    public function tenant_parameter_name_can_be_customized()
    {
        PathTenantResolver::$tenantParameterName = 'team';

        Route::group([
            'prefix' => '/{team}',
            'middleware' => InitializeTenancyByPath::class,
        ], function () {
            Route::get('/bar/{a}/{b}', function ($a, $b) {
                return "$a + $b";
            });
        });

        Tenant::create([
            'id' => 'acme',
        ]);

        $this
            ->get('/acme/bar/abc/xyz')
            ->assertContent('abc + xyz');

        // Parameter for resolver is changed, so the /{tenant}/foo route will no longer work.
        $this->expectException(RouteIsMissingTenantParameterException::class);

        $this
            ->withoutExceptionHandling()
            ->get('/acme/foo/abc/xyz');
    }
}
