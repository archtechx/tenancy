<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\UrlGenerator;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Resolvers\PathTenantResolver;
use Stancl\Tenancy\Overrides\TenancyUrlGenerator;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Bootstrappers\UrlGeneratorBootstrapper;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedByRequestDataException;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;
use function Stancl\Tenancy\Tests\pest;

beforeEach(function () {
    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
    TenancyUrlGenerator::$prefixRouteNames = false;
    TenancyUrlGenerator::$passTenantParameterToRoutes = false;
    UrlGeneratorBootstrapper::$addTenantParameterToDefaults = false;
});

afterEach(function () {
    TenancyUrlGenerator::$prefixRouteNames = false;
    TenancyUrlGenerator::$passTenantParameterToRoutes = false;
    UrlGeneratorBootstrapper::$addTenantParameterToDefaults = false;
});

test('url generator bootstrapper swaps the url generator instance correctly', function() {
    config(['tenancy.bootstrappers' => [UrlGeneratorBootstrapper::class]]);

    tenancy()->initialize(Tenant::create());
    expect(app('url'))->toBeInstanceOf(TenancyUrlGenerator::class);
    expect(url())->toBeInstanceOf(TenancyUrlGenerator::class);

    tenancy()->end();
    expect(app('url'))->toBeInstanceOf(UrlGenerator::class)
        ->not()->toBeInstanceOf(TenancyUrlGenerator::class);
    expect(url())->toBeInstanceOf(UrlGenerator::class)
        ->not()->toBeInstanceOf(TenancyUrlGenerator::class);
});

test('tenancy url generator can prefix route names passed to the route helper', function() {
    config([
        'tenancy.identification.resolvers.' . PathTenantResolver::class . '.tenant_route_name_prefix' => 'custom_prefix.',
    ]);

    Route::get('/central/home', fn () => '')->name('home');
    Route::get('/tenant/home', fn () => '')->name('custom_prefix.home');

    $tenant = Tenant::create();

    config(['tenancy.bootstrappers' => [UrlGeneratorBootstrapper::class]]);

    tenancy()->initialize($tenant);

    // Route names don't get prefixed when TenancyUrlGenerator::$prefixRouteNames is false (default)
    expect(route('home'))->toBe('http://localhost/central/home');

    // When $prefixRouteNames is true, the route name passed to the route() helper ('home') gets prefixed automatically.
    TenancyUrlGenerator::$prefixRouteNames = true;

    expect(route('home'))->toBe('http://localhost/tenant/home');

    // The 'custom_prefix.home' route name doesn't get prefixed -- it is already prefixed with 'custom_prefix.'
    expect(route('custom_prefix.home'))->toBe('http://localhost/tenant/home');

    // Ending tenancy reverts route() behavior changes
    tenancy()->end();

    expect(route('home'))->toBe('http://localhost/central/home');
});

test('path identification route helper behavior', function (bool $addTenantParameterToDefaults, bool $passTenantParameterToRoutes) {
    config(['tenancy.bootstrappers' => [UrlGeneratorBootstrapper::class]]);

    $appUrl = config('app.url');
    UrlGeneratorBootstrapper::$addTenantParameterToDefaults = $addTenantParameterToDefaults;
    TenancyUrlGenerator::$passTenantParameterToRoutes = $passTenantParameterToRoutes;

    $tenant = Tenant::create();
    $tenantKey = $tenant->getTenantKey();

    Route::get('/{tenant}/home', fn () => '')
        ->name('tenant.home')
        ->middleware(['tenant', InitializeTenancyByPath::class]);

    tenancy()->initialize($tenant);

    if (! $addTenantParameterToDefaults && ! $passTenantParameterToRoutes) {
        expect(fn () => route('tenant.home'))->toThrow(UrlGenerationException::class, 'Missing parameter: tenant');
    } else {
        // If at least *one* of the approaches was used, the parameter will make its way to the route
        expect(route('tenant.home'))->toBe("{$appUrl}/{$tenantKey}/home");
    }
})->with([true, false]) // UrlGeneratorBootstrapper::$addTenantParameterToDefaults
    ->with([true, false]); // TenancyUrlGenerator::$passTenantParameterToRoutes

test('request data identification route helper behavior', function (bool $addTenantParameterToDefaults, bool $passTenantParameterToRoutes) {
    config(['tenancy.bootstrappers' => [UrlGeneratorBootstrapper::class]]);

    $appUrl = config('app.url');
    UrlGeneratorBootstrapper::$addTenantParameterToDefaults = $addTenantParameterToDefaults;
    TenancyUrlGenerator::$passTenantParameterToRoutes = $passTenantParameterToRoutes;

    $tenant = Tenant::create();
    $tenantKey = $tenant->getTenantKey();

    Route::get('/tenant/home', fn () => tenant('id'))
        ->name('tenant.home')
        ->middleware(['tenant', InitializeTenancyByRequestData::class]);

    tenancy()->initialize($tenant);

    // todo0 test changing tenancy.identification.resolvers.<request data>.query_parameter

    if ($passTenantParameterToRoutes) {
        expect(route('tenant.home'))->toBe("{$appUrl}/tenant/home?tenant={$tenantKey}");
        pest()->get(route('tenant.home'))->assertSee($tenant->id);
    } else {
        expect(route('tenant.home'))->toBe("{$appUrl}/tenant/home");
        expect(fn () => $this->withoutExceptionHandling()->get(route('tenant.home')))->toThrow(TenantCouldNotBeIdentifiedByRequestDataException::class);
    }
})->with([true, false]) // UrlGeneratorBootstrapper::$addTenantParameterToDefaults
    ->with([true, false]); // TenancyUrlGenerator::$passTenantParameterToRoutes

test('setting extra model columns sets additional URL defaults', function () {
    Tenant::$extraCustomColumns = ['slug'];
    TenancyUrlGenerator::$passTenantParameterToRoutes = false;
    UrlGeneratorBootstrapper::$addTenantParameterToDefaults = true;

    config(['tenancy.bootstrappers' => [UrlGeneratorBootstrapper::class]]);
    config(['tenancy.identification.resolvers.' . PathTenantResolver::class . '.allowed_extra_model_columns' => ['slug']]);

    Schema::table('tenants', function (Blueprint $table) {
        $table->string('slug')->unique();
    });

    Route::get('/{tenant}/foo/{user}', function (string $user) {
        return tenant()->getTenantKey() . " $user";
    })->middleware([InitializeTenancyByPath::class, 'web'])->name('foo');

    Route::get('/{tenant:slug}/fooslug/{user}', function (string $user) {
        return tenant()->getTenantKey() . " $user";
    })->middleware([InitializeTenancyByPath::class, 'web'])->name('fooslug');

    $tenant = Tenant::create(['slug' => 'acme']);

    // In central context, no URL defaults are applied
    expect(route('foo', [$tenant->getTenantKey(), 'bar']))->toBe("http://localhost/{$tenant->getTenantKey()}/foo/bar");
    pest()->get(route('foo', [$tenant->getTenantKey(), 'bar']))->assertSee(tenant()->getTenantKey() . ' bar');
    tenancy()->end();

    expect(route('fooslug', ['acme', 'bar']))->toBe('http://localhost/acme/fooslug/bar');
    pest()->get(route('fooslug', ['acme', 'bar']))->assertSee(tenant()->getTenantKey() . ' bar');
    tenancy()->end();

    // In tenant context, URL defaults are applied
    tenancy()->initialize($tenant);
    expect(route('foo', ['bar']))->toBe("http://localhost/{$tenant->getTenantKey()}/foo/bar");
    pest()->get(route('foo', ['bar']))->assertSee(tenant()->getTenantKey() . ' bar');

    expect(route('fooslug', ['bar']))->toBe('http://localhost/acme/fooslug/bar');
    pest()->get(route('fooslug', ['bar']))->assertSee(tenant()->getTenantKey() . ' bar');
});

test('changing the tenant model column changes the default value for the tenant parameter', function () {
    Tenant::$extraCustomColumns = ['slug'];
    TenancyUrlGenerator::$passTenantParameterToRoutes = false;
    UrlGeneratorBootstrapper::$addTenantParameterToDefaults = true;

    config(['tenancy.bootstrappers' => [UrlGeneratorBootstrapper::class]]);
    config(['tenancy.identification.resolvers.' . PathTenantResolver::class . '.tenant_model_column' => 'slug']);

    Schema::table('tenants', function (Blueprint $table) {
        $table->string('slug')->unique();
    });

    Route::get('/{tenant}/foo/{user}', function (string $user) {
        return tenant()->getTenantKey() . " $user";
    })->middleware([InitializeTenancyByPath::class, 'web'])->name('foo');

    $tenant = Tenant::create(['slug' => 'acme']);

    // In central context, no URL defaults are applied
    expect(route('foo', ['acme', 'bar']))->toBe("http://localhost/acme/foo/bar");
    pest()->get(route('foo', ['acme', 'bar']))->assertSee(tenant()->getTenantKey() . ' bar');
    tenancy()->end();

    // In tenant context, URL defaults are applied
    tenancy()->initialize($tenant);
    expect(route('foo', ['bar']))->toBe("http://localhost/acme/foo/bar");
    pest()->get(route('foo', ['bar']))->assertSee(tenant()->getTenantKey() . ' bar');
});

test('changing the tenant parameter name is respected by the url generator', function () {
    Tenant::$extraCustomColumns = ['slug', 'slug2'];
    TenancyUrlGenerator::$passTenantParameterToRoutes = false;
    UrlGeneratorBootstrapper::$addTenantParameterToDefaults = true;

    config(['tenancy.bootstrappers' => [UrlGeneratorBootstrapper::class]]);
    config(['tenancy.identification.resolvers.' . PathTenantResolver::class . '.tenant_parameter_name' => 'team']);
    config(['tenancy.identification.resolvers.' . PathTenantResolver::class . '.tenant_model_column' => 'slug']);
    config(['tenancy.identification.resolvers.' . PathTenantResolver::class . '.allowed_extra_model_columns' => ['slug2']]);

    Schema::table('tenants', function (Blueprint $table) {
        $table->string('slug')->unique();
        $table->string('slug2')->unique();
    });

    Route::get('/{team}/foo/{user}', function (string $user) {
        return tenant()->getTenantKey() . " $user";
    })->middleware([InitializeTenancyByPath::class, 'web'])->name('foo');

    Route::get('/{team:slug2}/fooslug2/{user}', function (string $user) {
        return tenant()->getTenantKey() . " $user";
    })->middleware([InitializeTenancyByPath::class, 'web'])->name('fooslug2');

    $tenant = Tenant::create(['slug' => 'acme', 'slug2' => 'acme2']);

    // In central context, no URL defaults are applied
    expect(route('foo', ['acme', 'bar']))->toBe("http://localhost/acme/foo/bar");
    pest()->get(route('foo', ['acme', 'bar']))->assertSee(tenant()->getTenantKey() . ' bar');
    tenancy()->end();

    expect(route('fooslug2', ['acme2', 'bar']))->toBe("http://localhost/acme2/fooslug2/bar");
    pest()->get(route('fooslug2', ['acme2', 'bar']))->assertSee(tenant()->getTenantKey() . ' bar');
    tenancy()->end();

    // In tenant context, URL defaults are applied
    tenancy()->initialize($tenant);
    expect(route('foo', ['bar']))->toBe("http://localhost/acme/foo/bar");
    pest()->get(route('foo', ['bar']))->assertSee(tenant()->getTenantKey() . ' bar');

    expect(route('fooslug2', ['bar']))->toBe("http://localhost/acme2/fooslug2/bar");
    pest()->get(route('fooslug2', ['bar']))->assertSee(tenant()->getTenantKey() . ' bar');
});

test('url generator can override specific route names', function() {
    config(['tenancy.bootstrappers' => [UrlGeneratorBootstrapper::class]]);

    Route::get('/foo', fn () => 'foo')->name('foo');
    Route::get('/bar', fn () => 'bar')->name('bar');
    Route::get('/baz', fn () => 'baz')->name('baz'); // Not overridden

    TenancyUrlGenerator::$overrides = ['foo' => 'bar'];

    expect(route('foo'))->toBe(url('/foo'));
    expect(route('bar'))->toBe(url('/bar'));
    expect(route('baz'))->toBe(url('/baz'));

    tenancy()->initialize(Tenant::create());

    expect(route('foo'))->toBe(url('/bar'));
    expect(route('bar'))->toBe(url('/bar')); // not overridden
    expect(route('baz'))->toBe(url('/baz')); // not overridden

    // Bypass the override
    expect(route('foo', ['central' => true]))->toBe(url('/foo'));
});

test('both the name prefixing and the tenant parameter logic gets skipped when bypass parameter is used', function () {
    $tenantParameterName = PathTenantResolver::tenantParameterName();

    Route::get('/central/home', fn () => route('home'))->name('home');
    // Tenant route name prefix is 'tenant.' by default
    Route::get('/{tenant}/home', fn () => route('tenant.home'))->name('tenant.home')->middleware(['tenant', InitializeTenancyByPath::class]);

    $tenant = Tenant::create();
    $centralRouteUrl = route('home');
    $tenantRouteUrl = route('tenant.home', ['tenant' => $tenant->getTenantKey()]);
    config(['tenancy.bootstrappers' => [UrlGeneratorBootstrapper::class]]);

    TenancyUrlGenerator::$prefixRouteNames = true;
    TenancyUrlGenerator::$bypassParameter = 'bypassParameter';

    tenancy()->initialize($tenant);

    // The $bypassParameter parameter ('central' by default) can bypass the route name prefixing
    // When the bypass parameter is true, the generated route URL points to the route named 'home'
    expect(route('home', ['bypassParameter' => true]))->toBe($centralRouteUrl)
        // Bypass parameter prevents passing the tenant parameter directly
        ->not()->toContain($tenantParameterName . '=')
        // Bypass parameter gets removed from the generated URL automatically
        ->not()->toContain('bypassParameter');

    // When the bypass parameter is false, the generated route URL points to the prefixed route ('tenant.home')
    // The tenant parameter is not passed automatically since both
    // UrlGeneratorBootstrapper::$addTenantParameterToDefaults and TenancyUrlGenerator::$passTenantParameterToRoutes are false by default
    expect(route('home', ['bypassParameter' => false, 'tenant' => $tenant->getTenantKey()]))->toBe($tenantRouteUrl)
        ->not()->toContain('bypassParameter');
});
