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

    /** @test */
    public function invoke_works()
    {
        $this->assertSame(tenant('uuid'), tenant()('uuid'));
    }

    /** @test */
    public function initById_works()
    {
        $tenant = tenant()->create('foo.localhost');

        $this->assertNotSame($tenant, tenancy()->tenant);

        tenancy()->initById($tenant['uuid']);

        $this->assertSame($tenant, tenancy()->tenant);
    }

    /** @test */
    public function findByDomain_works()
    {
        $tenant = tenant()->create('foo.localhost');

        $this->assertSame($tenant, tenant()->findByDomain('foo.localhost'));
    }

    /** @test */
    public function getIdByDomain_works()
    {
        $tenant = tenant()->create('foo.localhost');
        $this->assertSame(tenant()->getTenantIdByDomain('foo.localhost'), tenant()->getIdByDomain('foo.localhost'));
    }

    /** @test */
    public function findWorks()
    {
        tenant()->create('dev.localhost');
        tenancy()->init('dev.localhost');

        $this->assertSame(tenant()->tenant, tenant()->find(tenant('uuid')));
    }

    /** @test */
    public function getTenantByIdWorks()
    {
        $tenant = tenant()->create('foo.localhost');
        
        $this->assertSame($tenant, tenancy()->getTenantById($tenant['uuid']));
    }
}
