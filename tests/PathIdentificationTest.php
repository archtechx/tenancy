<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Exceptions\RouteIsMissingTenantParameterException;
use Stancl\Tenancy\Exceptions\TenantColumnNotWhitelistedException;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedByPathException;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;
use Stancl\Tenancy\Resolvers\PathTenantResolver;
use Stancl\Tenancy\Tests\Etc\Tenant;
use function Stancl\Tenancy\Tests\pest;

beforeEach(function () {
    // Make sure the tenant parameter is set to 'tenant'
    config(['tenancy.identification.resolvers.' . PathTenantResolver::class . '.tenant_parameter_name' => 'tenant']);

    InitializeTenancyByPath::$onFail = null;
    Tenant::$extraCustomColumns = [];

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

test('the tenant model column can be customized in the config', function () {
    config(['tenancy.identification.resolvers.' . PathTenantResolver::class . '.tenant_model_column' => 'slug']);
    Tenant::$extraCustomColumns = ['slug'];

    Schema::table('tenants', function (Blueprint $table) {
        $table->string('slug')->unique();
    });

    $tenant = Tenant::create([
        'slug' => 'acme',
    ]);

    Route::get('/{tenant}/foo', function () {
        return tenant()->getTenantKey();
    })->middleware(InitializeTenancyByPath::class);

    $this->withoutExceptionHandling();
    pest()->get('/acme/foo')->assertSee($tenant->getTenantKey());
    expect(fn () => pest()->get($tenant->id . '/foo'))->toThrow(TenantCouldNotBeIdentifiedByPathException::class);

    Tenant::$extraCustomColumns = []; // static property reset
});

test('the tenant model column can be customized in the route definition', function () {
    Tenant::$extraCustomColumns = ['slug'];
    config(['tenancy.identification.resolvers.' . PathTenantResolver::class . '.allowed_extra_model_columns' => ['slug']]);

    Schema::table('tenants', function (Blueprint $table) {
        $table->string('slug')->unique();
    });

    $tenant = Tenant::create([
        'slug' => 'acme',
    ]);

    Route::get('/{tenant}/foo', function () {
        return tenant()->getTenantKey();
    })->middleware(InitializeTenancyByPath::class);

    Route::get('/{tenant:slug}/bar', function () {
        return tenant()->getTenantKey();
    })->middleware(InitializeTenancyByPath::class);

    $this->withoutExceptionHandling();

    // No binding field defined
    pest()->get($tenant->getTenantKey() . '/foo')->assertSee($tenant->getTenantKey());
    expect(fn () => pest()->get('/acme/foo'))->toThrow(TenantCouldNotBeIdentifiedByPathException::class);

    // Binding field defined
    pest()->get('/acme/bar')->assertSee($tenant->getTenantKey());
    expect(fn () => pest()->get($tenant->id . '/bar'))->toThrow(TenantCouldNotBeIdentifiedByPathException::class);

    Tenant::$extraCustomColumns = []; // static property reset
});

test('any extra model column needs to be whitelisted', function () {
    Tenant::$extraCustomColumns = ['slug'];

    Schema::table('tenants', function (Blueprint $table) {
        $table->string('slug')->unique();
    });

    $tenant = Tenant::create([
        'slug' => 'acme',
    ]);

    Route::get('/{tenant:slug}/foo', function () {
        return tenant()->getTenantKey();
    })->middleware(InitializeTenancyByPath::class);

    $this->withoutExceptionHandling();
    expect(fn () => pest()->get('/acme/foo'))->toThrow(TenantColumnNotWhitelistedException::class);

    // After whitelisting the column it works
    config(['tenancy.identification.resolvers.' . PathTenantResolver::class . '.allowed_extra_model_columns' => ['slug']]);
    pest()->get('/acme/foo')->assertSee($tenant->getTenantKey());

    Tenant::$extraCustomColumns = []; // static property reset
});
