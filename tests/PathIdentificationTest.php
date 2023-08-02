<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Exceptions\RouteIsMissingTenantParameterException;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedByPathException;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;
use Stancl\Tenancy\Resolvers\PathTenantResolver;
use Stancl\Tenancy\Tests\Etc\Tenant;

beforeEach(function () {
    // Make sure the tenant parameter is set to 'tenant'
    config(['tenancy.identification.resolvers.' . PathTenantResolver::class . '.tenant_parameter_name' => 'tenant']);

    InitializeTenancyByPath::$onFail = null;

    Route::group([
        'prefix' => '/{tenant}',
        'middleware' => InitializeTenancyByPath::class,
    ], function () {
        Route::get('/foo/{a}/{b}', function ($a, $b) {
            return "$a + $b";
        })->name('foo');

        Route::get('/baz/{a}/{b}', function ($a, $b) {
            return "$a - $b";
        })->name('baz');
    });
});

test('tenant can be identified by path', function () {
    Tenant::create([
        'id' => 'acme',
    ]);

    expect(tenancy()->initialized)->toBeFalse();

    pest()->get('/acme/foo/abc/xyz');

    expect(tenancy()->initialized)->toBeTrue();
    expect(tenant('id'))->toBe('acme');
});

test('route actions dont get the tenant id', function () {
    Tenant::create([
        'id' => 'acme',
    ]);

    expect(tenancy()->initialized)->toBeFalse();

    pest()
        ->get('/acme/foo/abc/xyz')
        ->assertContent('abc + xyz');

    expect(tenancy()->initialized)->toBeTrue();
    expect(tenant('id'))->toBe('acme');
});

test('exception is thrown when tenant cannot be identified by path', function () {
    pest()->expectException(TenantCouldNotBeIdentifiedByPathException::class);

    $this
        ->withoutExceptionHandling()
        ->get('/acme/foo/abc/xyz');

    expect(tenancy()->initialized)->toBeFalse();
});

test('onfail logic can be customized', function () {
    InitializeTenancyByPath::$onFail = function () {
        return response('foo');
    };

    pest()
        ->get('/acme/foo/abc/xyz')
        ->assertContent('foo');

    InitializeTenancyByPath::$onFail = null;
});

test('an exception is thrown when the route does not have the tenant parameter', function () {
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

    pest()->expectException(RouteIsMissingTenantParameterException::class);

    $this
        ->withoutExceptionHandling()
        ->get('/bar/foo/bar');
});

test('tenant parameter name can be customized', function () {
    config(['tenancy.identification.resolvers.' . PathTenantResolver::class . '.tenant_parameter_name' => 'team']);

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

    pest()
        ->get('/acme/bar/abc/xyz')
        ->assertContent('abc + xyz');

    // Parameter for resolver is changed, so the /{tenant}/foo route will no longer work.
    pest()->expectException(RouteIsMissingTenantParameterException::class);

    $this
        ->withoutExceptionHandling()
        ->get('/acme/foo/abc/xyz');
});

test('tenant parameter is set for all routes as the default parameter once the tenancy initialized', function () {
    Tenant::create([
        'id' => 'acme',
    ]);

    expect(tenancy()->initialized)->toBeFalse();

    // make a request that will initialize tenancy
    pest()->get(route('foo', ['tenant' => 'acme', 'a' => 1, 'b' => 2]));

    expect(tenancy()->initialized)->toBeTrue();
    expect(tenant('id'))->toBe('acme');

    // assert that the route WITHOUT the tenant parameter matches the route WITH the tenant parameter
    expect(route('baz', ['a' => 1, 'b' => 2]))->toBe(route('baz', ['tenant' => 'acme', 'a' => 1, 'b' => 2]));

    expect(route('baz', ['a' => 1, 'b' => 2]))->toBe('http://localhost/acme/baz/1/2'); // assert the full route string
    pest()->get(route('baz', ['a' => 1, 'b' => 2]))->assertOk(); // Assert route don't need tenant parameter
});

test('tenant parameter does not have to be the first in order to initialize tenancy', function() {
    Tenant::create([
        'id' => $tenantId = 'another-tenant',
    ]);

    Route::get('/another/route/{a}/{tenant}/{b}', function ($a, $b) {
        return "$a + $b + " . tenant()->getTenantKey();
    })->middleware(InitializeTenancyByPath::class)->name('tenant-parameter-is-second');

    pest()->get("/another/route/foo/$tenantId/bar")->assertSee("foo + bar + $tenantId");
});

test('central route can have a parameter with the same name as the tenant parameter', function() {
    config(['tenancy.identification.resolvers.' . PathTenantResolver::class . '.tenant_parameter_name' => 'team']);
    $tenantKey = Tenant::create()->getTenantKey();

    Route::get('/central/route/{team}/{a}/{b}', function ($team, $a, $b) {
        return "$a + $b + $team";
    })->middleware('central')->name('central-route');

    pest()->get("/central/route/{$tenantKey}/foo/bar")->assertSee("foo + bar + {$tenantKey}");

    expect(tenancy()->initialized)->toBeFalse();

    // With kernel path identification
    app(Kernel::class)->pushMiddleware(InitializeTenancyByPath::class);

    pest()->get("/central/route/{$tenantKey}/foo/bar")->assertSee("foo + bar + {$tenantKey}");

    expect(tenancy()->initialized)->toBeFalse();
});
