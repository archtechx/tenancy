<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Exceptions\RouteIsMissingTenantParameterException;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedByPathException;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;
use Stancl\Tenancy\Resolvers\PathTenantResolver;
use Stancl\Tenancy\Tests\Etc\Tenant;

beforeEach(function () {
    PathTenantResolver::$tenantParameterName = 'tenant';

    Route::group([
        'prefix' => '/{tenant}',
        'middleware' => InitializeTenancyByPath::class,
    ], function () {
        Route::get('/foo/{a}/{b}', function ($a, $b) {
            return "$a + $b";
        });
    });
});

afterEach(function () {
    // Global state cleanup
    PathTenantResolver::$tenantParameterName = 'tenant';
});

test('tenant can be identified by path', function () {
    Tenant::create([
        'id' => 'acme',
    ]);

    expect(tenancy()->initialized)->toBeFalse();

    $this->get('/acme/foo/abc/xyz');

    expect(tenancy()->initialized)->toBeTrue();
    expect(tenant('id'))->toBe('acme');
});

test('route actions dont get the tenant id', function () {
    Tenant::create([
        'id' => 'acme',
    ]);

    expect(tenancy()->initialized)->toBeFalse();

    $this
        ->get('/acme/foo/abc/xyz')
        ->assertContent('abc + xyz');

    expect(tenancy()->initialized)->toBeTrue();
    expect(tenant('id'))->toBe('acme');
});

test('exception is thrown when tenant cannot be identified by path', function () {
    $this->expectException(TenantCouldNotBeIdentifiedByPathException::class);

    $this
        ->withoutExceptionHandling()
        ->get('/acme/foo/abc/xyz');

    expect(tenancy()->initialized)->toBeFalse();
});

test('onfail logic can be customized', function () {
    InitializeTenancyByPath::$onFail = function () {
        return 'foo';
    };

    $this
        ->get('/acme/foo/abc/xyz')
        ->assertContent('foo');
});

test('an exception is thrown when the routes first parameter is not tenant', function () {
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
});

test('tenant parameter name can be customized', function () {
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
});
