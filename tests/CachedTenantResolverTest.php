<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;
use Stancl\Tenancy\Tests\Etc\Tenant;

class CachedTenantResolverTest extends TestCase
{
    public function tearDown(): void
    {
        DomainTenantResolver::$shouldCache = false;

        parent::tearDown();
    }

    /** @test */
    public function tenants_can_be_resolved_using_the_cached_resolver()
    {
        $tenant = Tenant::create();
        $tenant->domains()->create([
            'domain' => 'acme',
        ]);

        $this->assertTrue($tenant->is(app(DomainTenantResolver::class)->resolve('acme')));
        $this->assertTrue($tenant->is(app(DomainTenantResolver::class)->resolve('acme')));
    }

    /** @test */
    public function the_underlying_resolver_is_not_touched_when_using_the_cached_resolver()
    {
        $tenant = Tenant::create();
        $tenant->domains()->create([
            'domain' => 'acme',
        ]);

        DB::enableQueryLog();

        DomainTenantResolver::$shouldCache = false;

        $this->assertTrue($tenant->is(app(DomainTenantResolver::class)->resolve('acme')));
        DB::flushQueryLog();
        $this->assertTrue($tenant->is(app(DomainTenantResolver::class)->resolve('acme')));
        $this->assertNotEmpty(DB::getQueryLog()); // not empty

        DomainTenantResolver::$shouldCache = true;

        $this->assertTrue($tenant->is(app(DomainTenantResolver::class)->resolve('acme')));
        DB::flushQueryLog();
        $this->assertTrue($tenant->is(app(DomainTenantResolver::class)->resolve('acme')));
        $this->assertEmpty(DB::getQueryLog()); // empty
    }

    /** @test */
    public function cache_is_invalidated_when_the_tenant_is_updated()
    {
        $tenant = Tenant::create();
        $tenant->createDomain([
            'domain' => 'acme',
        ]);

        DB::enableQueryLog();

        DomainTenantResolver::$shouldCache = true;

        $this->assertTrue($tenant->is(app(DomainTenantResolver::class)->resolve('acme')));
        DB::flushQueryLog();
        $this->assertTrue($tenant->is(app(DomainTenantResolver::class)->resolve('acme')));
        $this->assertEmpty(DB::getQueryLog()); // empty

        $tenant->update([
            'foo' => 'bar',
        ]);

        DB::flushQueryLog();
        $this->assertTrue($tenant->is(app(DomainTenantResolver::class)->resolve('acme')));
        $this->assertNotEmpty(DB::getQueryLog()); // not empty
    }

    /** @test */
    public function cache_is_invalidated_when_a_tenants_domain_is_changed()
    {
        $tenant = Tenant::create();
        $tenant->createDomain([
            'domain' => 'acme',
        ]);

        DB::enableQueryLog();

        DomainTenantResolver::$shouldCache = true;

        $this->assertTrue($tenant->is(app(DomainTenantResolver::class)->resolve('acme')));
        DB::flushQueryLog();
        $this->assertTrue($tenant->is(app(DomainTenantResolver::class)->resolve('acme')));
        $this->assertEmpty(DB::getQueryLog()); // empty

        $tenant->createDomain([
            'domain' => 'bar',
        ]);

        DB::flushQueryLog();
        $this->assertTrue($tenant->is(app(DomainTenantResolver::class)->resolve('acme')));
        $this->assertNotEmpty(DB::getQueryLog()); // not empty

        DB::flushQueryLog();
        $this->assertTrue($tenant->is(app(DomainTenantResolver::class)->resolve('bar')));
        $this->assertNotEmpty(DB::getQueryLog()); // not empty
    }
}
