<?php

use Illuminate\Support\Facades\URL;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Bootstrappers\RootUrlBootstrapper;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Bootstrappers\UrlGeneratorBootstrapper;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;
use Stancl\Tenancy\Overrides\TenancyUrlGenerator;

beforeEach(function () {
    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
    RootUrlBootstrapper::$rootUrlOverride = null;
    RootUrlBootstrapper::$rootUrlOverrideInTests = true;
});

afterEach(function () {
    RootUrlBootstrapper::$rootUrlOverride = null;
    RootUrlBootstrapper::$rootUrlOverrideInTests = false;
});

test('root url bootstrapper overrides the root url when tenancy gets initialized and reverts the url to the central one when ending tenancy', function() {
    config(['tenancy.bootstrappers' => [RootUrlBootstrapper::class]]);

    Route::group([
        'middleware' => InitializeTenancyBySubdomain::class,
    ], function () {
        Route::get('/', function () {
            return true;
        })->name('home');
    });

    $baseUrl = url(route('home'));
    config(['app.url' => $baseUrl]);

    $rootUrlOverride = function (Tenant $tenant) use ($baseUrl) {
        $scheme = str($baseUrl)->before('://');
        $hostname = str($baseUrl)->after($scheme . '://');

        return $scheme . '://' . $tenant->getTenantKey() . '.' . $hostname;
    };

    RootUrlBootstrapper::$rootUrlOverride = $rootUrlOverride;

    $tenant = Tenant::create();
    $tenantUrl = $rootUrlOverride($tenant);

    expect($tenantUrl)->not()->toBe($baseUrl);

    expect(url(route('home')))->toBe($baseUrl);
    expect(URL::to('/'))->toBe($baseUrl);
    expect(config('app.url'))->toBe($baseUrl);

    tenancy()->initialize($tenant);

    expect(url(route('home')))->toBe($tenantUrl);
    expect(URL::to('/'))->toBe($tenantUrl);
    expect(config('app.url'))->toBe($tenantUrl);

    tenancy()->end();

    expect(url(route('home')))->toBe($baseUrl);
    expect(URL::to('/'))->toBe($baseUrl);
    expect(config('app.url'))->toBe($baseUrl);
});

test('root url bootstrapper can be used with url generator bootstrapper', function() {
    /**
     * Order matters when combining these two bootstrappers.
     * Before overriding the URL generator's root URL, we need to bind TenancyUrlGenerator.
     * Otherwise (when using RootUrlBootstrapper BEFORE UrlGeneratorBootstrapper),
     * the original URL generator's root URL will be changed, and only after that will the TenancyUrlGenerator bound,
     * ultimately making the root URL override pointless.
     */
    config(['tenancy.bootstrappers' => [UrlGeneratorBootstrapper::class, RootUrlBootstrapper::class]]);

    TenancyUrlGenerator::$prefixRouteNames = true;
    TenancyUrlGenerator::$passTenantParameterToRoutes = true;
    RootUrlBootstrapper::$rootUrlOverride = fn (Tenant $tenant, string $originalRootUrl) => $originalRootUrl . '/' . $tenant->getTenantKey();

    Route::get('/home', fn () => 'home')->name('home');
    Route::get('/{tenant}/home', fn () => 'tenant.home')->name('tenant.home')->middleware(InitializeTenancyByPath::class);

    expect(url('/home'))->toBe('http://localhost/home');

    expect(route('home'))->toBe('http://localhost/home');
    expect(route('home', absolute: false))->toBe('/home');

    tenancy()->initialize(Tenant::create(['id' => 'acme']));

    // The url() helper should generate the full URL containing the tenant key
    expect(url('/home'))->toBe('http://localhost/acme/home');

    /**
     * The absolute path should return the correct absolute path, containing just one tenant key,
     * and the relative path should still be /home.
     *
     * We use string manipulation in the route() method override for this to behave correctly.
     *
     * @see TenancyUrlGenerator
     */
    expect(route('home'))->toBe('http://localhost/acme/home');
    expect(route('home', absolute: false))->toBe('/home');
});
