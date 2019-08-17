<?php

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\Artisan;

class TelescopeTest extends TestCase
{
    public $autoCreateTenant = false;
    public $autoInitTenancy = false;

    public function setUp(): void
    {
        Artisan::call('telescope:install');
        Artisan::call('migrate');
    }

    /** @test */
    public function tags_are_added_on_tenant_routes()
    {
        $this->markTestIncomplete();
    }

    /** @test */
    public function tags_are_not_added_on_non_tenant_routes()
    {
        // AppSP ones should be still added
        $this->markTestIncomplete();
    }

    /** @test */
    public function tags_are_merged_with_users_tags()
    {
        $this->markTestIncomplete();
    }
}
