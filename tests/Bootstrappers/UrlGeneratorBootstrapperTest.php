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
    Route::get('/central/home', fn () => route('home'))->name('home');
    // Tenant route name prefix is 'tenant.' by default
    Route::get('/tenant/home', fn () => route('tenant.home'))->name('tenant.home');

    $tenant = Tenant::create();
    $centralRouteUrl = route('home');
    $tenantRouteUrl = route('tenant.home');

    config(['tenancy.bootstrappers' => [UrlGeneratorBootstrapper::class]]);

    tenancy()->initialize($tenant);

    // Route names don't get prefixed when TenancyUrlGenerator::$prefixRouteNames is false (default)
    expect(route('home'))->toBe($centralRouteUrl);

    // When $prefixRouteNames is true, the route name passed to the route() helper ('home') gets prefixed with 'tenant.' automatically.
    TenancyUrlGenerator::$prefixRouteNames = true;

    expect(route('home'))->toBe($tenantRouteUrl);

    // The 'tenant.home' route name doesn't get prefixed -- it is already prefixed with 'tenant.'
    expect(route('tenant.home'))->toBe($tenantRouteUrl);

    // Ending tenancy reverts route() behavior changes
    tenancy()->end();

    expect(route('home'))->toBe($centralRouteUrl);
});

test('the route helper can receive the tenant parameter automatically', function (
    string $identification,
    bool $addTenantParameterToDefaults,
    bool $passTenantParameterToRoutes,
) {
    config(['tenancy.bootstrappers' => [UrlGeneratorBootstrapper::class]]);

    $appUrl = config('app.url');

    UrlGeneratorBootstrapper::$addTenantParameterToDefaults = $addTenantParameterToDefaults;

    // When the tenant parameter isn't added to defaults, the tenant parameter has to be passed "manually"
    // by setting $passTenantParameterToRoutes to true. This is only preferable with query string identification.
    // With path identification, this ultimately doesn't have any effect
    // if UrlGeneratorBootstrapper::$addTenantParameterToDefaults is true,
    // but TenancyUrlGenerator::$passTenantParameterToRoutes can still be used instead.
    TenancyUrlGenerator::$passTenantParameterToRoutes = $passTenantParameterToRoutes;

    $tenant = Tenant::create();
    $tenantKey = $tenant->getTenantKey();

    Route::get('/central/home', fn () => route('home'))->name('home');

    $tenantRoute = $identification === InitializeTenancyByPath::class ? "/{tenant}/home" : "/tenant/home";

    Route::get($tenantRoute, fn () => route('tenant.home'))
        ->name('tenant.home')
        ->middleware(['tenant', $identification]);

    tenancy()->initialize($tenant);

    $expectedUrl = match (true) {
        $identification === InitializeTenancyByRequestData::class && $passTenantParameterToRoutes => "{$appUrl}/tenant/home?tenant={$tenantKey}",
        $identification === InitializeTenancyByRequestData::class => "{$appUrl}/tenant/home", // $passTenantParameterToRoutes is false
        $identification === InitializeTenancyByPath::class && ($addTenantParameterToDefaults || $passTenantParameterToRoutes) => "{$appUrl}/{$tenantKey}/home",
        $identification === InitializeTenancyByPath::class => null, // Should throw an exception -- route() doesn't receive the tenant parameter in this case
    };

    if ($expectedUrl === null) {
        expect(fn () => route('tenant.home'))->toThrow(UrlGenerationException::class, 'Missing parameter: tenant');
    } else {
        expect(route('tenant.home'))->toBe($expectedUrl);
    }
})->with([InitializeTenancyByPath::class, InitializeTenancyByRequestData::class])
    ->with([true, false]) // UrlGeneratorBootstrapper::$addTenantParameterToDefaults
    ->with([true, false]); // TenancyUrlGenerator::$passTenantParameterToRoutes

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
