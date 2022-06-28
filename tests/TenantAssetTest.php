<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use Stancl\Tenancy\Controllers\TenantAssetsController;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;
use Stancl\Tenancy\Tests\Etc\Tenant;

uses(Stancl\Tenancy\Tests\TestCase::class);

beforeEach(function () {
    config(['tenancy.bootstrappers' => [
        FilesystemTenancyBootstrapper::class,
    ]]);

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
});

afterEach(function () {
    // Cleanup
    TenantAssetsController::$tenancyMiddleware = InitializeTenancyByDomain::class;
});

test('asset can be accessed using the url returned by the tenant asset helper', function () {
    TenantAssetsController::$tenancyMiddleware = InitializeTenancyByRequestData::class;

    $tenant = Tenant::create();
    tenancy()->initialize($tenant);

    $filename = 'testfile' . $this->randomString(10);
    Storage::disk('public')->put($filename, 'bar');
    $path = storage_path("app/public/$filename");

    // response()->file() returns BinaryFileResponse whose content is
    // inaccessible via getContent, so ->assertSee() can't be used
    $this->assertFileExists($path);
    $response = $this->get(tenant_asset($filename), [
        'X-Tenant' => $tenant->id,
    ]);

    $response->assertSuccessful();

    $f = fopen($path, 'r');
    $content = fread($f, filesize($path));
    fclose($f);

    $this->assertSame('bar', $content);
});

test('asset helper returns a link to tenant asset controller when asset url is null', function () {
    config(['app.asset_url' => null]);

    $tenant = Tenant::create();
    tenancy()->initialize($tenant);

    $this->assertSame(route('stancl.tenancy.asset', ['path' => 'foo']), asset('foo'));
});

test('asset helper returns a link to an external url when asset url is not null', function () {
    config(['app.asset_url' => 'https://an-s3-bucket']);

    $tenant = Tenant::create();
    tenancy()->initialize($tenant);

    $this->assertSame("https://an-s3-bucket/tenant{$tenant->id}/foo", asset('foo'));
});

test('global asset helper returns the same url regardless of tenancy initialization', function () {
    $original = global_asset('foobar');
    $this->assertSame(asset('foobar'), global_asset('foobar'));

    $tenant = Tenant::create();
    tenancy()->initialize($tenant);

    $this->assertSame($original, global_asset('foobar'));
});

test('asset helper tenancy can be disabled', function () {
    $original = asset('foo');

    config([
        'app.asset_url' => null,
        'tenancy.filesystem.asset_helper_tenancy' => false,
    ]);

    $tenant = Tenant::create();
    tenancy()->initialize($tenant);

    $this->assertSame($original, asset('foo'));
});

// Helpers
function getEnvironmentSetUp($app)
{
    parent::getEnvironmentSetUp($app);

    $app->booted(function () {
        if (file_exists(base_path('routes/tenant.php'))) {
            Route::middleware(['web'])
                ->namespace(test()->app['config']['tenancy.tenant_route_namespace'] ?? 'App\Http\Controllers')
                ->group(base_path('routes/tenant.php'));
        }
    });
}
