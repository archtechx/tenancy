<?php

declare(strict_types=1);

use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Event;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\File;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;
use Stancl\Tenancy\Bootstrappers\UrlGeneratorBootstrapper;
use Stancl\Tenancy\Actions\CloneRoutesAsTenant;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use Stancl\Tenancy\Controllers\TenantAssetController;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Features\ViteBundler;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Overrides\TenancyUrlGenerator;
use Stancl\Tenancy\Tests\Etc\Tenant;

use function Stancl\Tenancy\Tests\pest;

beforeEach(function () {
    config([
        'app.asset_url' => null,
        'tenancy.filesystem.asset_helper_override' => true,
        'tenancy.bootstrappers' => [FilesystemTenancyBootstrapper::class],
    ]);

    TenancyUrlGenerator::$prefixRouteNames = false;
    TenancyUrlGenerator::$passTenantParameterToRoutes = true;
    TenantAssetController::$headers = [];

    $manifestPath = public_path('build/manifest.json');
    File::ensureDirectoryExists(dirname($manifestPath));
    File::put($manifestPath, json_encode([
        'foo' => [
            'file' => 'assets/foo-AbC123.js',
            'src'  => 'js/foo.js',
        ],
    ]));

    $this->tenant = Tenant::create();
    $this->assetPath = 'foo';
});

test('vite bundler ensures vite assets use global_asset when asset_helper_override is enabled', function () {
    config(['tenancy.features' => [ViteBundler::class]]);

    app(CloneRoutesAsTenant::class)->handle();

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);

    tenancy()->initialize($this->tenant);

    expect(asset($this->assetPath))
        ->toBe(route('stancl.tenancy.asset', ['path' => $this->assetPath]))
        ->and(global_asset($this->assetPath))
        ->toBe('http://localhost/' . $this->assetPath);

    $viteAssetUrl = app(Vite::class)->asset($this->assetPath);
    $expectedGlobalUrl = global_asset('build/assets/foo-AbC123.js');

    expect($viteAssetUrl)
        ->toBe($expectedGlobalUrl)
        ->and($viteAssetUrl)
        ->not->toBe(route('stancl.tenancy.asset', ['path' => 'build/assets/foo-AbC123.js']));
});

test('vite uses tenant assets when asset_helper_override is enabled without ViteBundler', function () {
    config(['tenancy.features' => []]);

    app(CloneRoutesAsTenant::class)->handle();

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);

    tenancy()->initialize($this->tenant);

    expect(asset($this->assetPath))
        ->toBe(route('stancl.tenancy.asset', ['path' => $this->assetPath]))
        ->and(global_asset($this->assetPath))
        ->toBe('http://localhost/' . $this->assetPath);

    $viteAssetUrl = app(Vite::class)->asset($this->assetPath);

    expect($viteAssetUrl)
        ->toBe(route('stancl.tenancy.asset', ['path' => 'build/assets/foo-AbC123.js']))
        ->and($viteAssetUrl)
        ->not->toBe(global_asset('build/assets/foo-AbC123.js'));
});

test('vite asset helper works correctly with path identification', function (bool $kernelIdentification) {
    TenancyUrlGenerator::$prefixRouteNames = true;
    TenancyUrlGenerator::$passTenantParameterToRoutes = true;

    config([
        'tenancy.filesystem.asset_helper_override' => true,
        'tenancy.features' => [ViteBundler::class],
        'tenancy.identification.default_middleware' => InitializeTenancyByPath::class,
        'tenancy.bootstrappers' => array_merge([UrlGeneratorBootstrapper::class], config('tenancy.bootstrappers')),
    ]);

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);

    $viteRoute = Route::prefix('{tenant}')->get('/vite_helper', function () {
        return app(Vite::class)->asset('foo');
    })->name('tenant.helper.vite');

    $assetRoute = Route::prefix('{tenant}')->get('/asset_helper', function () {
        return asset('foo');
    })->name('tenant.helper.asset');

    if ($kernelIdentification) {
        app(Kernel::class)->pushMiddleware(InitializeTenancyByPath::class);
    } else {
        $viteRoute->middleware(InitializeTenancyByPath::class);
        $assetRoute->middleware(InitializeTenancyByPath::class);
    }

    app(CloneRoutesAsTenant::class)->handle();

    tenancy()->initialize(Tenant::create());

    expect(pest()->get(route('tenant.helper.asset'))->getContent())
        ->toBe(route('stancl.tenancy.asset', ['path' => 'foo']));
    expect(pest()->get(route('tenant.helper.vite'))->getContent())
        ->toBe(global_asset('build/assets/foo-AbC123.js'));
})->with([
    'kernel identification' => true,
    'route-level identification' => false,
]);
