<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

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

class TenantAssetTest extends TestCase
{
    public function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app->booted(function () {
            if (file_exists(base_path('routes/tenant.php'))) {
                Route::middleware(['web'])
                    ->namespace($this->app['config']['tenancy.tenant_route_namespace'] ?? 'App\Http\Controllers')
                    ->group(base_path('routes/tenant.php'));
            }
        });
    }

    public function setUp(): void
    {
        parent::setUp();

        config(['tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
        ]]);

        Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    }

    public function tearDown(): void
    {
        parent::tearDown();

        // Cleanup
        TenantAssetsController::$tenancyMiddleware = InitializeTenancyByDomain::class;
    }

    /** @test */
    public function asset_can_be_accessed_using_the_url_returned_by_the_tenant_asset_helper()
    {
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
    }

    /** @test */
    public function asset_helper_returns_a_link_to_TenantAssetController_when_asset_url_is_null()
    {
        config(['app.asset_url' => null]);

        $tenant = Tenant::create();
        tenancy()->initialize($tenant);

        $this->assertSame(route('stancl.tenancy.asset', ['path' => 'foo']), asset('foo'));
    }

    /** @test */
    public function asset_helper_returns_a_link_to_an_external_url_when_asset_url_is_not_null()
    {
        config(['app.asset_url' => 'https://an-s3-bucket']);

        $tenant = Tenant::create();
        tenancy()->initialize($tenant);

        $this->assertSame("https://an-s3-bucket/tenant{$tenant->id}/foo", asset('foo'));
    }

    /** @test */
    public function global_asset_helper_returns_the_same_url_regardless_of_tenancy_initialization()
    {
        $original = global_asset('foobar');
        $this->assertSame(asset('foobar'), global_asset('foobar'));

        $tenant = Tenant::create();
        tenancy()->initialize($tenant);

        $this->assertSame($original, global_asset('foobar'));
    }

    /** @test */
    public function asset_helper_tenancy_can_be_disabled()
    {
        $original = asset('foo');

        config([
            'app.asset_url' => null,
            'tenancy.filesystem.asset_helper_tenancy' => false,
        ]);

        $tenant = Tenant::create();
        tenancy()->initialize($tenant);

        $this->assertSame($original, asset('foo'));
    }
}
