<?php

namespace Stancl\Tenancy\Tests;

class TenantManagerTest extends TestCase
{
    public $autoCreateTenant = false;
    public $autoInitTenancy = false;

    /** @test */
    public function current_tenant_is_stored_in_the_tenant_property()
    {
        $tenant = tenant()->create('localhost');

        tenancy()->init('localhost');

        $this->assertSame($tenant, tenancy()->tenant);
    }

    // todo write more tests
}
