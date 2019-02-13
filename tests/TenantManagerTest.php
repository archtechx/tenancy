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
    public function find_works()
    {
        tenant()->create('dev.localhost');
        tenancy()->init('dev.localhost');

        $this->assertSame(tenant()->tenant, tenant()->find(tenant('uuid')));
    }

    /** @test */
    public function getTenantById_works()
    {
        $tenant = tenant()->create('foo.localhost');
        
        $this->assertSame($tenant, tenancy()->getTenantById($tenant['uuid']));
    }

    /** @test */
    public function init_returns_the_tenant()
    {
        $tenant = tenant()->create('foo.localhost');

        $this->assertSame($tenant, tenancy()->init('foo.localhost'));
    }

    /** @test */
    public function initById_returns_the_tenant()
    {
        $tenant = tenant()->create('foo.localhost');
        $uuid = $tenant['uuid'];

        $this->assertSame($tenant, tenancy()->initById($uuid));
    }

    /** @test */
    public function create_returns_the_supplied_domain()
    {
        $domain = 'foo.localhost';

        $this->assertSame($domain, tenant()->create($domain)['domain']);
    }

    /** @test */
    public function find_by_domain_throws_an_exception_when_an_unused_domain_is_supplied()
    {
        $this->expectException(\Exception::class);
        tenancy()->findByDomain('nonexistent.domain');
    }
}
