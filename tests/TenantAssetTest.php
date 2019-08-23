<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

class TenantAssetTest extends TestCase
{
    /** @test */
    public function asset_can_be_accessed_using_the_url_returned_by_the_tenant_asset_helper()
    {
        $filename = 'testfile' . $this->randomString(10);
        \Storage::disk('public')->put($filename, 'bar');
        $path = storage_path("app/public/$filename");

        // response()->file() returns BinaryFileResponse whose content is
        // inaccessible via getContent, so ->assertSee() can't be used
        $this->get(tenant_asset($filename))->assertSuccessful();
        $this->assertFileExists($path);

        $f = \fopen($path, 'r');
        $content = \fread($f, \filesize($path));
        \fclose($f);

        $this->assertSame('bar', $content);
    }
}
