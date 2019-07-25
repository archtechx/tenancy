<?php

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
    public function findByDomain_throws_an_exception_when_an_unused_domain_is_supplied()
    {
        $this->expectException(\Exception::class);
        tenancy()->findByDomain('nonexistent.domain');
    }

    /** @test */
    public function tenancy_can_be_ended()
    {
        $originals = [
            'databaseName' => DB::connection()->getDatabaseName(),
            'storage_path' => storage_path(),
            'storage_root' => Storage::disk('local')->getAdapter()->getPathPrefix(),
            'cache' => app('cache'),
        ];

        // Verify that these assertions are the right way for testing this
        $this->assertSame($originals['databaseName'], DB::connection()->getDatabaseName());
        $this->assertSame($originals['storage_path'], storage_path());
        $this->assertSame($originals['storage_root'], Storage::disk('local')->getAdapter()->getPathPrefix());
        $this->assertSame($originals['cache'], app('cache'));

        tenant()->create('foo.localhost');
        tenancy()->init('foo.localhost');
    
        $this->assertNotSame($originals['databaseName'], DB::connection()->getDatabaseName());
        $this->assertNotSame($originals['storage_path'], storage_path());
        $this->assertNotSame($originals['storage_root'], Storage::disk('local')->getAdapter()->getPathPrefix());
        $this->assertNotSame($originals['cache'], app('cache'));

        tenancy()->end();

        $this->assertSame($originals['databaseName'], DB::connection()->getDatabaseName());
        $this->assertSame($originals['storage_path'], storage_path());
        $this->assertSame($originals['storage_root'], Storage::disk('local')->getAdapter()->getPathPrefix());
        $this->assertSame($originals['cache'], app('cache'));
    }

    /** @test */
    public function tenancy_can_be_ended_after_reidentification()
    {
        $originals = [
            'databaseName' => DB::connection()->getDatabaseName(),
            'storage_path' => storage_path(),
            'storage_root' => Storage::disk('local')->getAdapter()->getPathPrefix(),
            'cache' => app('cache'),
        ];

        tenant()->create('foo.localhost');
        tenancy()->init('foo.localhost');
    
        $this->assertNotSame($originals['databaseName'], DB::connection()->getDatabaseName());
        $this->assertNotSame($originals['storage_path'], storage_path());
        $this->assertNotSame($originals['storage_root'], Storage::disk('local')->getAdapter()->getPathPrefix());
        $this->assertNotSame($originals['cache'], app('cache'));

        tenancy()->end();

        $this->assertSame($originals['databaseName'], DB::connection()->getDatabaseName());
        $this->assertSame($originals['storage_path'], storage_path());
        $this->assertSame($originals['storage_root'], Storage::disk('local')->getAdapter()->getPathPrefix());
        $this->assertSame($originals['cache'], app('cache'));

        // Reidentify tenant
        tenant()->create('bar.localhost');
        tenancy()->init('bar.localhost');

        $this->assertNotSame($originals['databaseName'], DB::connection()->getDatabaseName());
        $this->assertNotSame($originals['storage_path'], storage_path());
        $this->assertNotSame($originals['storage_root'], Storage::disk('local')->getAdapter()->getPathPrefix());
        $this->assertNotSame($originals['cache'], app('cache'));

        tenancy()->end();

        $this->assertSame($originals['databaseName'], DB::connection()->getDatabaseName());
        $this->assertSame($originals['storage_path'], storage_path());
        $this->assertSame($originals['storage_root'], Storage::disk('local')->getAdapter()->getPathPrefix());
        $this->assertSame($originals['cache'], app('cache'));
    }

    /** @test */
    public function tenant_can_be_deleted()
    {
        $tenant = tenant()->create('foo.localhost');
        tenant()->delete($tenant['uuid']);
        $this->assertSame([], tenancy()->all()->toArray());
        
        $tenant = tenant()->create('foo.localhost');
        $this->assertSame([$tenant], tenancy()->all()->toArray());
    }
}
