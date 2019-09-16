<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Tenant;

class TenantManagerTest extends TestCase
{
    public $autoCreateTenant = false;
    public $autoInitTenancy = false;

    /** @test */
    public function current_tenant_can_be_retrieved_using_getTenant()
    {
        $tenant = Tenant::new()->withDomains(['localhost'])->save();

        tenancy()->init('test.localhost');

        $this->assertSame($tenant, tenancy()->getTenant());
    }

    /** @test */
    public function invoke_works()
    {
        Tenant::new()->withDomains(['foo.localhost'])->save();
        tenancy()->init('foo.localhost');

        $this->assertSame(tenant('id'), tenant()('id'));
    }

    /** @test */
    public function initById_works()
    {
        $tenant = Tenant::new()->withDomains(['foo.localhost'])->save();

        $this->assertNotSame($tenant, tenancy()->tenant);

        tenancy()->initById($tenant['id']);

        $this->assertSame($tenant, tenancy()->tenant);
    }

    /** @test */
    public function findByDomain_works()
    {
        $tenant = Tenant::new()->withDomains(['foo.localhost'])->save();

        $this->assertSame($tenant, tenant()->findByDomain('foo.localhost'));
    }

    /** @test */
    public function getIdByDomain_works()
    {
        $tenant = Tenant::new()->withDomains(['foo.localhost'])->save();
        $this->assertSame(tenant()->getTenantIdByDomain('foo.localhost'), tenant()->getIdByDomain('foo.localhost'));
    }

    /** @test */
    public function find_works()
    {
        Tenant::new()->withDomains(['dev.localhost'])->save();
        tenancy()->init('dev.localhost');

        $this->assertSame(tenant()->tenant, tenant()->find(tenant('id')));
    }

    /** @test */
    public function getTenantById_works()
    {
        $tenant = Tenant::new()->withDomains(['foo.localhost'])->save();

        $this->assertSame($tenant, tenancy()->getTenantById($tenant['id']));
    }

    /** @test */
    public function create_returns_the_supplied_domain()
    {
        $domain = 'foo.localhost';

        $this->assertSame($domain, Tenant::new()->withDomains([$domain])->save()['domain']);
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

        Tenant::new()->withDomains(['foo.localhost'])->save();
        tenancy()->init('foo.localhost');

        $this->assertNotSame($originals['databaseName'], DB::connection()->getDatabaseName());
        $this->assertNotSame($originals['storage_path'], storage_path());
        $this->assertNotSame($originals['storage_root'], Storage::disk('local')->getAdapter()->getPathPrefix());
        $this->assertNotSame($originals['cache'], app('cache'));

        tenancy()->endTenancy();

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

        Tenant::new()->withDomains(['foo.localhost'])->save();
        tenancy()->init('foo.localhost');

        $this->assertNotSame($originals['databaseName'], DB::connection()->getDatabaseName());
        $this->assertNotSame($originals['storage_path'], storage_path());
        $this->assertNotSame($originals['storage_root'], Storage::disk('local')->getAdapter()->getPathPrefix());
        $this->assertNotSame($originals['cache'], app('cache'));

        tenancy()->endTenancy();

        $this->assertSame($originals['databaseName'], DB::connection()->getDatabaseName());
        $this->assertSame($originals['storage_path'], storage_path());
        $this->assertSame($originals['storage_root'], Storage::disk('local')->getAdapter()->getPathPrefix());
        $this->assertSame($originals['cache'], app('cache'));

        // Reidentify tenant
        Tenant::new()->withDomains(['bar.localhost'])->save();
        tenancy()->init('bar.localhost');

        $this->assertNotSame($originals['databaseName'], DB::connection()->getDatabaseName());
        $this->assertNotSame($originals['storage_path'], storage_path());
        $this->assertNotSame($originals['storage_root'], Storage::disk('local')->getAdapter()->getPathPrefix());
        $this->assertNotSame($originals['cache'], app('cache'));

        tenancy()->endTenancy();

        $this->assertSame($originals['databaseName'], DB::connection()->getDatabaseName());
        $this->assertSame($originals['storage_path'], storage_path());
        $this->assertSame($originals['storage_root'], Storage::disk('local')->getAdapter()->getPathPrefix());
        $this->assertSame($originals['cache'], app('cache'));
    }

    /** @test */
    public function tenant_can_be_deleted()
    {
        $tenant = Tenant::new()->withDomains(['foo.localhost'])->save();
        tenant()->delete($tenant['id']);
        $this->assertSame([], tenancy()->all()->toArray());

        $tenant = Tenant::new()->withDomains(['foo.localhost'])->save();
        $this->assertSame([$tenant], tenancy()->all()->toArray());
    }

    /** @test */
    public function all_returns_a_list_of_all_tenants()
    {
        $tenant1 = Tenant::new()->withDomains(['foo.localhost'])->save();
        $tenant2 = Tenant::new()->withDomains(['bar.localhost'])->save();
        $this->assertEqualsCanonicalizing([$tenant1, $tenant2], tenancy()->all()->toArray());
    }

    /** @test */
    public function properites_can_be_passed_in_the_create_method()
    {
        $data = ['plan' => 'free', 'subscribed_until' => '2020-01-01'];
        $tenant = Tenant::new()->withDomains(['foo.localhost', $data])->save();

        $tenant_data = $tenant;
        unset($tenant_data['id']);
        unset($tenant_data['domain']);

        $this->assertSame($data, $tenant_data);
    }

    /** @test */
    public function database_name_can_be_passed_in_the_create_method()
    {
        $database = 'abc';
        config(['tenancy.database_name_key' => '_stancl_tenancy_database_name']);

        $tenant = tenant()->create('foo.localhost', [
            '_stancl_tenancy_database_name' => $database,
        ]);

        $this->assertSame($database, tenant()->getDatabaseName($tenant));
    }

    /** @test */
    public function id_cannot_be_changed()
    {
        // todo
    }
}
