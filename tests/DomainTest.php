<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models;
use Stancl\Tenancy\Database\Models\Domain;
use Stancl\Tenancy\Exceptions\DomainOccupiedByOtherTenantException;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedOnDomainException;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;

class DomainTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        Route::group([
            'middleware' => InitializeTenancyByDomain::class,
        ], function () {
            Route::get('/foo/{a}/{b}', function ($a, $b) {
                return "$a + $b";
            });
        });

        config(['tenancy.tenant_model' => DomainTenant::class]);
    }

    /** @test */
    public function tenant_can_be_identified_using_hostname()
    {
        $tenant = DomainTenant::create();

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
        $tenant = DomainTenant::create();

        $tenant->domains()->create([
            'domain' => 'foo.localhost',
        ]);

        $tenant2 = DomainTenant::create();

        $this->expectException(DomainOccupiedByOtherTenantException::class);
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

    /** @test */
    public function tenant_can_be_identified_by_domain()
    {
        $tenant = DomainTenant::create([
            'id' => 'acme',
        ]);

        $tenant->domains()->create([
            'domain' => 'foo.localhost',
        ]);

        $this->assertFalse(tenancy()->initialized);

        $this
            ->get('http://foo.localhost/foo/abc/xyz')
            ->assertSee('abc + xyz');

        $this->assertTrue(tenancy()->initialized);
        $this->assertSame('acme', tenant('id'));
    }

    /** @test */
    public function onfail_logic_can_be_customized()
    {
        InitializeTenancyByDomain::$onFail = function () {
            return 'foo';
        };

        $this
            ->get('http://foo.localhost/foo/abc/xyz')
            ->assertSee('foo');
    }

    /** @test */
    public function domains_are_always_lowercase()
    {
        $tenant = DomainTenant::create();

        $tenant->domains()->create([
            'domain' => 'CAPITALS',
        ]);

        $this->assertSame('capitals', Domain::first()->domain);
    }
}

class DomainTenant extends Models\Tenant
{
    use HasDomains;
}
