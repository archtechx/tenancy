<?php

namespace Stancl\Tenancy\Tests;

class TenantAssetTest extends TestCase
{
    /** @test */
    public function asset_can_be_accessed_using_the_url_returned_by_the_tenant_asset_helper()
    {
        $this->markTestIncomplete();
        $filename = 'testfile' . $this->randomString(10);
        \Storage::disk('public')->put($filename, 'bar');
        $this->get(tenant_asset($filename))->assertSee('bar');
    }
}
