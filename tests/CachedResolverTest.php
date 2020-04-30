<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\Cache;
use Stancl\Tenancy\StorageDrivers\Database\DatabaseStorageDriver;
use Stancl\Tenancy\Tenant;

class CachedResolverTest extends TestCase
{
    public $autoCreateTenant = false;
    public $autoInitTenancy = false;

    public function setUp(): void
    {
        parent::setUp();

        if (config('tenancy.storage_driver') !== 'db') {
            $this->markTestSkipped('This test is only relevant for the DB storage driver.');
        }

        config(['tenancy.storage_drivers.db.cache_store' => config('cache.default')]);
    }

    /** @test */
    public function a_query_is_not_made_for_tenant_id_once_domain_is_cached()
    {
        $tenant = Tenant::new()
            ->withData(['foo' => 'bar'])
            ->withDomains(['foo.localhost'])
            ->save();

        // query is made
        $queried = tenancy()->findByDomain('foo.localhost');
        $this->assertEquals($tenant->data, $queried->data);
        $this->assertSame($tenant->domains, $queried->domains);

        // cache is set
        $this->assertEquals($tenant->id, Cache::get('_tenancy_domain_to_id:foo.localhost'));
        $this->assertEquals($tenant->data, Cache::get('_tenancy_id_to_data:' . $tenant->id));
        $this->assertSame($tenant->domains, Cache::get('_tenancy_id_to_domains:' . $tenant->id));

        // query is not made
        DatabaseStorageDriver::getCentralConnection()->enableQueryLog();
        $cached = tenancy()->findByDomain('foo.localhost');
        $this->assertEquals($tenant->data, $cached->data);
        $this->assertSame($tenant->domains, $cached->domains);
        $this->assertSame([], DatabaseStorageDriver::getCentralConnection()->getQueryLog());
    }

    /** @test */
    public function a_query_is_not_made_for_tenant_once_id_is_cached()
    {
        $tenant = Tenant::new()
            ->withData(['foo' => 'bar'])
            ->withDomains(['foo.localhost'])
            ->save();

        // query is made
        $queried = tenancy()->find($tenant->id);
        $this->assertEquals($tenant->data, $queried->data);
        $this->assertSame($tenant->domains, $queried->domains);

        // cache is set
        $this->assertEquals($tenant->data, Cache::get('_tenancy_id_to_data:' . $tenant->id));
        $this->assertSame($tenant->domains, Cache::get('_tenancy_id_to_domains:' . $tenant->id));

        // query is not made
        DatabaseStorageDriver::getCentralConnection()->enableQueryLog();
        $cached = tenancy()->find($tenant->id);
        $this->assertEquals($tenant->data, $cached->data);
        $this->assertSame($tenant->domains, $cached->domains);
        $this->assertSame([], DatabaseStorageDriver::getCentralConnection()->getQueryLog());
    }

    /** @test */
    public function modifying_tenant_domains_invalidates_the_cached_domain_to_id_mapping()
    {
        $tenant = Tenant::new()
            ->withDomains(['foo.localhost', 'bar.localhost'])
            ->save();

        // queried
        $this->assertSame($tenant->id, tenancy()->findByDomain('foo.localhost')->id);
        $this->assertSame($tenant->id, tenancy()->findByDomain('bar.localhost')->id);

        // assert cache set
        $this->assertSame($tenant->id, Cache::get('_tenancy_domain_to_id:foo.localhost'));
        $this->assertSame($tenant->id, Cache::get('_tenancy_domain_to_id:bar.localhost'));

        $tenant
            ->removeDomains(['foo.localhost', 'bar.localhost'])
            ->addDomains(['xyz.localhost'])
            ->save();

        // assert neither domain is cached
        $this->assertSame(null, Cache::get('_tenancy_domain_to_id:foo.localhost'));
        $this->assertSame(null, Cache::get('_tenancy_domain_to_id:bar.localhost'));
        $this->assertSame(null, Cache::get('_tenancy_domain_to_id:xyz.localhost'));
    }

    /** @test */
    public function modifying_tenants_data_invalidates_tenant_data_cache()
    {
        $tenant = Tenant::new()->withData(['foo' => 'bar'])->save();

        // cache record is set
        $this->assertSame('bar', tenancy()->find($tenant->id)->get('foo'));
        $this->assertSame('bar', Cache::get('_tenancy_id_to_data:' . $tenant->id)['foo']);

        // cache record is invalidated
        $tenant->set('foo', 'xyz');
        $this->assertSame(null, Cache::get('_tenancy_id_to_data:' . $tenant->id));

        // cache record is set
        $this->assertSame('xyz', tenancy()->find($tenant->id)->get('foo'));
        $this->assertSame('xyz', Cache::get('_tenancy_id_to_data:' . $tenant->id)['foo']);

        // cache record is invalidated
        $tenant->foo = 'abc';
        $tenant->save();
        $this->assertSame(null, Cache::get('_tenancy_id_to_data:' . $tenant->id));
    }

    /** @test */
    public function modifying_tenants_domains_invalidates_tenant_domain_cache()
    {
        $tenant = Tenant::new()
            ->withData(['foo' => 'bar'])
            ->withDomains(['foo.localhost'])
            ->save();

        // cache record is set
        $this->assertSame(['foo.localhost'], tenancy()->find($tenant->id)->domains);
        $this->assertSame(['foo.localhost'], Cache::get('_tenancy_id_to_domains:' . $tenant->id));

        // cache record is invalidated
        $tenant->addDomains(['bar.localhost'])->save();
        $this->assertEquals(null, Cache::get('_tenancy_id_to_domains:' . $tenant->id));

        $this->assertEquals(['foo.localhost', 'bar.localhost'], tenancy()->find($tenant->id)->domains);
    }

    /** @test */
    public function deleting_a_tenant_invalidates_all_caches()
    {
        $tenant = Tenant::new()
            ->withData(['foo' => 'bar'])
            ->withDomains(['foo.localhost'])
            ->save();

        tenancy()->findByDomain('foo.localhost');
        $this->assertEquals($tenant->id, Cache::get('_tenancy_domain_to_id:foo.localhost'));
        $this->assertEquals($tenant->data, Cache::get('_tenancy_id_to_data:' . $tenant->id));
        $this->assertEquals(['foo.localhost'], Cache::get('_tenancy_id_to_domains:' . $tenant->id));

        $tenant->delete();
        $this->assertEquals(null, Cache::get('_tenancy_domain_to_id:foo.localhost'));
        $this->assertEquals(null, Cache::get('_tenancy_id_to_data:' . $tenant->id));
        $this->assertEquals(null, Cache::get('_tenancy_id_to_domains:' . $tenant->id));
    }
}
