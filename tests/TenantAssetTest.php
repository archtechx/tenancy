<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Stancl\Tenancy\Tenant;

class TenantAssetTest extends TestCase
{
    public $autoCreateTenant = false;
    public $autoInitTenancy = false;

    /** @test */
    public function asset_can_be_accessed_using_the_url_returned_by_the_tenant_asset_helper()
    {
        Tenant::create('localhost');
        tenancy()->init('localhost');

        $filename = 'testfile' . $this->randomString(10);
        \Storage::disk('public')->put($filename, 'bar');
        $path = storage_path("app/public/$filename");

        // response()->file() returns BinaryFileResponse whose content is
        // inaccessible via getContent, so ->assertSee() can't be used
        $this->assertFileExists($path);
        $response = $this->get(tenant_asset($filename));

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

        Tenant::create('foo.localhost');
        tenancy()->init('foo.localhost');

        $this->assertSame(route('stancl.tenancy.asset', ['path' => 'foo']), asset('foo'));
    }

    /** @test */
    public function asset_helper_returns_a_link_to_an_external_url_when_asset_url_is_not_null()
    {
        config(['app.asset_url' => 'https://an-s3-bucket']);

        $tenant = Tenant::create(['foo.localhost']);
        tenancy()->init('foo.localhost');

        $this->assertSame("https://an-s3-bucket/tenant{$tenant->id}/foo", asset('foo'));
    }

    /** @test */
    public function global_asset_helper_returns_the_same_url_regardless_of_tenancy_initialization()
    {
        $original = global_asset('foobar');
        $this->assertSame(asset('foobar'), global_asset('foobar'));

        Tenant::create(['foo.localhost']);
        tenancy()->init('foo.localhost');

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

        Tenant::create('foo.localhost');
        tenancy()->init('foo.localhost');

        $this->assertSame($original, asset('foo'));
    }
}
