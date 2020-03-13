<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Stancl\Tenancy\Tenant;

class CachedResolverTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        if (config('tenancy.storage_driver') !== 'db') {
            $this->markTestSkipped('This test is only relevant for the DB storage driver.');
        }
    }

    /** @test */
    public function a_query_is_not_made_for_tenant_id_once_domain_is_cached()
    {
        $tenant = Tenant::new()->withDomains(['foo.localhost']);

        // todo assert query is made:
        $queried = tenancy()->findByDomain('foo.localhost');

        // todo assert query is not made but cache call is made:
        $cached = tenancy()->findByDomain('foo.localhost');
    }

    /** @test */
    public function a_query_is_not_made_for_tenant_once_id_is_cached()
    {
        $tenant = Tenant::new()->withData(['id' => '123']);

        // todo assert query is made:
        $queried = tenancy()->find('123');

        // todo assert query is not made but cache call is made:
        $cached = tenancy()->find('123');
    }

    /** @test */
    public function modifying_tenants_domains_updates_domains_in_the_cached_domain_to_id_mapping()
    {
    }

    /** @test */
    public function modifying_tenants_data_updates_data_in_the_cached_id_to_tenant_data_mapping()
    {
        $tenant = Tenant::new()->withData(['id' => '123', 'foo' => 'bar']);

        // todo assert cache record is set
        $this->assertSame('bar', tenancy()->find('123')->get('foo'));

        // todo assert cache record is updated
        $tenant->set('foo', 'xyz');

        $this->assertSame('xyz', tenancy()->find('123')->get('foo'));
    }
}
