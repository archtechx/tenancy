<?php

declare(strict_types=1);

use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Actions\CloneRoutesAsTenant;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\UrlGeneratorBootstrapper;
use Stancl\Tenancy\Controllers\TenantAssetController;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Overrides\TenancyUrlGenerator;
use function Stancl\Tenancy\Tests\pest;

beforeEach(function () {
    config(['tenancy.bootstrappers' => [
        FilesystemTenancyBootstrapper::class,
    ]]);

    TenancyUrlGenerator::$prefixRouteNames = false;
    TenancyUrlGenerator::$passTenantParameterToRoutes = true;
    TenantAssetController::$headers = [];

    /** @var CloneRoutesAsTenant $cloneAction */
    $cloneAction = app(CloneRoutesAsTenant::class);
    $cloneAction->handle(Route::getRoutes()->getByName('stancl.tenancy.asset'));

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
});

test('asset can be accessed using the url returned by the tenant asset helper', function () {
    config(['tenancy.identification.default_middleware' => InitializeTenancyByRequestData::class]);

    $tenant = Tenant::create();
    tenancy()->initialize($tenant);

    $filename = 'testfile' . pest()->randomString(10);
    Storage::disk('public')->put($filename, 'bar');
    $path = storage_path("app/public/$filename");

    // response()->file() returns BinaryFileResponse whose content is
    // inaccessible via getContent, so ->assertSee() can't be used
    expect($path)->toBeFile();
    $response = pest()->get(tenant_asset($filename), [
        'X-Tenant' => $tenant->id,
    ]);

    $response->assertSuccessful();

    $f = fopen($path, 'r');
    $content = fread($f, filesize($path));
    fclose($f);

    expect($content)->toBe('bar');
});

test('asset helper returns a link to tenant asset controller when asset url is null', function () {
    config(['app.asset_url' => null]);
    config(['tenancy.filesystem.asset_helper_override' => true]);

    $tenant = Tenant::create();
    tenancy()->initialize($tenant);

    expect(asset('foo'))->toBe(route('stancl.tenancy.asset', ['path' => 'foo']));

    tenancy()->end();
    expect(asset('foo'))->toBe('http://localhost/foo');
});

test('asset helper returns a link to an external url when asset url is not null', function () {
    config(['app.asset_url' => 'https://an-s3-bucket']);
    config(['tenancy.filesystem.asset_helper_override' => true]);

    $tenant = Tenant::create();
    tenancy()->initialize($tenant);

    expect(asset('foo'))->toBe("https://an-s3-bucket/tenant{$tenant->id}/foo");

    tenancy()->end();
    expect(asset('foo'))->toBe('https://an-s3-bucket/foo');
});

test('asset helper works correctly with path identification', function (bool $kernelIdentification) {
    TenancyUrlGenerator::$prefixRouteNames = true;
    TenancyUrlGenerator::$passTenantParameterToRoutes = true;

    config(['tenancy.filesystem.asset_helper_override' => true]);
    config(['tenancy.identification.default_middleware' => InitializeTenancyByPath::class]);
    config(['tenancy.bootstrappers' => array_merge([UrlGeneratorBootstrapper::class], config('tenancy.bootstrappers'))]);

    $tenantAssetRoute = Route::prefix('{tenant}')->get('/tenant_helper', function () {
        return tenant_asset('foo');
    })->name('tenant.helper.tenant');

    $assetRoute = Route::prefix('{tenant}')->get('/asset_helper', function () {
        return asset('foo');
    })->name('tenant.helper.asset');

    if ($kernelIdentification) {
        app(Kernel::class)->pushMiddleware(InitializeTenancyByPath::class);
    } else {
        $assetRoute->middleware(InitializeTenancyByPath::class);
        $tenantAssetRoute->middleware(InitializeTenancyByPath::class);
    }

    /** @var CloneRoutesAsTenant $cloneAction */
    $cloneAction = app(CloneRoutesAsTenant::class);

    $cloneAction->handle();

    tenancy()->initialize(Tenant::create());

    expect(pest()->get(route('tenant.helper.asset'))->getContent())->toBe(route('stancl.tenancy.asset', ['path' => 'foo']));
    expect(pest()->get(route('tenant.helper.tenant'))->getContent())->toBe(route('stancl.tenancy.asset', ['path' => 'foo']));
})->with([
    'kernel identification' => true,
    'route-level identification' => false,
]);

test('TenantAssetController headers are configurable', function () {
    TenantAssetController::$headers = function (Request $request) {
        return ['X-Foo' => 'Bar'];
    };

    $tenant = Tenant::create();
    tenancy()->initialize($tenant);
    $tenant->createDomain('foo.localhost');

    $filename = 'testfile' . pest()->randomString(10);
    Storage::disk('public')->put($filename, 'bar');

    $this->withoutExceptionHandling();

    $response = pest()->get("http://foo.localhost/tenancy/assets/$filename", [
        'X-Tenant' => $tenant->id,
    ]);

    $response->assertSuccessful();
    $response->assertHeader('X-Foo', 'Bar');

    TenantAssetController::$headers = []; // reset static property
});

test('global asset helper returns the same url regardless of tenancy initialization', function () {
    $original = global_asset('foobar');
    expect(global_asset('foobar'))->toBe(asset('foobar'));

    $tenant = Tenant::create();
    tenancy()->initialize($tenant);

    expect(global_asset('foobar'))->toBe($original);
});

test('asset helper tenancy can be disabled', function () {
    $original = asset('foo');

    config([
        'app.asset_url' => null,
        'tenancy.filesystem.asset_helper_override' => false,
    ]);

    $tenant = Tenant::create();
    tenancy()->initialize($tenant);

    expect(asset('foo'))->toBe($original);
});

test('test asset controller returns a 404 when no path is provided', function () {
    config(['tenancy.identification.default_middleware' => InitializeTenancyByRequestData::class]);

    $tenant = Tenant::create();

    tenancy()->initialize($tenant);

    $this->withoutExceptionHandling();
    pest()->expectExceptionMessage('Empty path'); // outside tests this is a 404

    pest()->get(tenant_asset(null), [
        'X-Tenant' => $tenant->id,
    ])->assertNotFound();
});

test('tenant asset controller returns a 404 when the storage root doesnt exist', function () {
    config(['tenancy.identification.default_middleware' => InitializeTenancyByRequestData::class]);

    $tenant = Tenant::create();

    tenancy()->initialize($tenant);

    $storageRoot = storage_path("app/public");

    if (is_dir($storageRoot)) {
        rmdir(storage_path("app/public"));
    }

    $this->withoutExceptionHandling();
    pest()->expectExceptionMessage("Storage root doesn't exist"); // outside tests this is a 404

    pest()->get(tenant_asset('foo.txt'), [
        'X-Tenant' => $tenant->id,
    ]);
});

test('tenant asset controller returns a 404 when accessing a nonexistent file', function () {
    config(['tenancy.identification.default_middleware' => InitializeTenancyByRequestData::class]);

    $tenant = Tenant::create();

    tenancy()->initialize($tenant);

    $storageRoot = storage_path("app/public");

    if (! is_dir($storageRoot)) {
        mkdir(storage_path("app/public"), recursive: true);
    }

    $this->withoutExceptionHandling();
    pest()->expectExceptionMessage("Accessing a nonexistent file"); // outside tests this is a 404

    pest()->get(tenant_asset('foo.txt'), [
        'X-Tenant' => $tenant->id,
    ]);
});

test('test asset controller returns a 404 when accessing a file outside the storage root', function () {
    config(['tenancy.identification.default_middleware' => InitializeTenancyByRequestData::class]);

    $tenant = Tenant::create();

    tenancy()->initialize($tenant);

    $storageRoot = storage_path("app/public");

    if (! is_dir($storageRoot)) {
        mkdir(storage_path("app/public"), recursive: true);
        file_put_contents(storage_path('app/foo.txt'), 'bar');
    }

    $this->withoutExceptionHandling();
    pest()->expectExceptionMessage('Accessing a file outside the storage root'); // outside tests this is a 404

    pest()->get(tenant_asset('../foo.txt'), [
        'X-Tenant' => $tenant->id,
    ]);
});
