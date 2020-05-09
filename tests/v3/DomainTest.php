<?php

namespace Stancl\Tenancy\Tests\v3;

use Stancl\Tenancy\Database\Models;
use Stancl\Tenancy\Database\Models\Concerns\HasDomains;
use Stancl\Tenancy\Exceptions\DomainsOccupiedByOtherTenantException;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedOnDomainException;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;
use Stancl\Tenancy\Tests\TestCase;

class DomainTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        config(['tenancy.tenant_model' => Tenant::class]);
    }

    /** @test */
    public function tenant_can_be_identified_using_hostname()
    {
        $tenant = Tenant::create();

        $id = $tenant->id;

        $tenant->domains()->create([
            'domain' => 'foo.localhost',
        ]);

        $resolvedTenant = app(DomainTenantResolver::class)->resolve('foo.localhost');

        $this->assertSame($id, $resolvedTenant->id);
        $this->assertSame(['foo.localhost'], $resolvedTenant->domains->pluck('domain')->toArray());
    }

    /** @test */
    public function a_domain_can_belong_to_only_one_tenant()
    {
        $tenant = Tenant::create();

        $tenant->domains()->create([
            'domain' => 'foo.localhost',
        ]);

        $tenant2 = Tenant::create();

        $this->expectException(DomainsOccupiedByOtherTenantException::class);
        $tenant2->domains()->create([
            'domain' => 'foo.localhost',
        ]);
    }

    /** @test */
    public function an_exception_is_thrown_if_tenant_cannot_be_identified()
    {
        $this->expectException(TenantCouldNotBeIdentifiedOnDomainException::class);

        app(DomainTenantResolver::class)->resolve('foo.localhost');
    }
}

class Tenant extends Models\Tenant
{
    use HasDomains;
}
