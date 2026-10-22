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
use function Stancl\Tenancy\Tests\pest;

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

test('root url bootstrapper restores the original app url when tenancy ends during a request', function () {
    config([
        'tenancy.bootstrappers' => [RootUrlBootstrapper::class],
        'app.url' => 'http://localhost',
    ]);

    RootUrlBootstrapper::$rootUrlOverride = fn (Tenant $tenant) => 'http://' . $tenant->getTenantKey() . '.localhost';

    Route::get('/app-url', function () {
        tenancy()->end();

        return config('app.url');
    })->middleware(InitializeTenancyBySubdomain::class);

    Tenant::create(['id' => 'acme'])->createDomain('acme');

    // If tenancy ends inside a request, the original configured app.url is restored
    pest()->get('http://acme.localhost/app-url')->assertContent('http://localhost');
});
