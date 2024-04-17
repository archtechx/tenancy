<?php

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
use Stancl\Tenancy\Bootstrappers\UrlGeneratorBootstrapper;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;

beforeEach(function () {
    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
    TenancyUrlGenerator::$prefixRouteNames = false;
    TenancyUrlGenerator::$passTenantParameterToRoutes = true;
});

afterEach(function () {
    TenancyUrlGenerator::$prefixRouteNames = false;
    TenancyUrlGenerator::$passTenantParameterToRoutes = true;
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

test('url generator bootstrapper can prefix route names passed to the route helper', function() {
    Route::get('/central/home', fn () => route('home'))->name('home');
    // Tenant route name prefix is 'tenant.' by default
    Route::get('/{tenant}/home', fn () => route('tenant.home'))->name('tenant.home')->middleware(['tenant', InitializeTenancyByPath::class]);

    $tenant = Tenant::create();
    $tenantKey = $tenant->getTenantKey();
    $centralRouteUrl = route('home');
    $tenantRouteUrl = route('tenant.home', ['tenant' => $tenantKey]);
    TenancyUrlGenerator::$bypassParameter = 'bypassParameter';

    config(['tenancy.bootstrappers' => [UrlGeneratorBootstrapper::class]]);

    tenancy()->initialize($tenant);

    // Route names don't get prefixed when TenancyUrlGenerator::$prefixRouteNames is false
    expect(route('home'))->not()->toBe($centralRouteUrl);
    // When TenancyUrlGenerator::$passTenantParameterToRoutes is true (default)
    // The route helper receives the tenant parameter
    // So in order to generate central URL, we have to pass the bypass parameter
    expect(route('home', ['bypassParameter' => true]))->toBe($centralRouteUrl);


    TenancyUrlGenerator::$prefixRouteNames = true;
    // The $prefixRouteNames property is true
    // The route name passed to the route() helper ('home') gets prefixed prefixed with 'tenant.' automatically
    expect(route('home'))->toBe($tenantRouteUrl);

    // The 'tenant.home' route name doesn't get prefixed because it is already prefixed with 'tenant.'
    // Also, the route receives the tenant parameter automatically
    expect(route('tenant.home'))->toBe($tenantRouteUrl);

    // Ending tenancy reverts route() behavior changes
    tenancy()->end();

    expect(route('home'))->toBe($centralRouteUrl);
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
    expect(route('home', ['bypassParameter' => false]))->toBe($tenantRouteUrl)
        ->not()->toContain('bypassParameter');
});

test('url generator bootstrapper can make route helper generate links with the tenant parameter', function() {
    Route::get('/query_string', fn () => route('query_string'))->name('query_string')->middleware(['universal', InitializeTenancyByRequestData::class]);
    Route::get('/path', fn () => route('path'))->name('path');
    Route::get('/{tenant}/path', fn () => route('tenant.path'))->name('tenant.path')->middleware([InitializeTenancyByPath::class]);

    $tenant = Tenant::create();
    $tenantKey = $tenant->getTenantKey();
    $queryStringCentralUrl = route('query_string');
    $queryStringTenantUrl = route('query_string', ['tenant' => $tenantKey]);
    $pathCentralUrl = route('path');
    $pathTenantUrl = route('tenant.path', ['tenant' => $tenantKey]);

    // Makes the route helper receive the tenant parameter whenever available
    // Unless the bypass parameter is true
    TenancyUrlGenerator::$passTenantParameterToRoutes = true;

    TenancyUrlGenerator::$bypassParameter = 'bypassParameter';

    config(['tenancy.bootstrappers' => [UrlGeneratorBootstrapper::class]]);

    expect(route('path'))->toBe($pathCentralUrl);
    // Tenant parameter required, but not passed since tenancy wasn't initialized
    expect(fn () => route('tenant.path'))->toThrow(UrlGenerationException::class);

    tenancy()->initialize($tenant);

    // Tenant parameter is passed automatically
    expect(route('path'))->not()->toBe($pathCentralUrl); // Parameter added as query string – bypassParameter needed
    expect(route('path', ['bypassParameter' => true]))->toBe($pathCentralUrl);
    expect(route('tenant.path'))->toBe($pathTenantUrl);

    expect(route('query_string'))->toBe($queryStringTenantUrl)->toContain('tenant=');
    expect(route('query_string', ['bypassParameter' => 'true']))->toBe($queryStringCentralUrl)->not()->toContain('tenant=');

    tenancy()->end();

    expect(route('query_string'))->toBe($queryStringCentralUrl);

    // Tenant parameter required, but shouldn't be passed since tenancy isn't initialized
    expect(fn () => route('tenant.path'))->toThrow(UrlGenerationException::class);

    // Route-level identification
    pest()->get("http://localhost/query_string")->assertSee($queryStringCentralUrl);
    pest()->get("http://localhost/query_string?tenant=$tenantKey")->assertSee($queryStringTenantUrl);
    pest()->get("http://localhost/path")->assertSee($pathCentralUrl);
    pest()->get("http://localhost/$tenantKey/path")->assertSee($pathTenantUrl);
});
