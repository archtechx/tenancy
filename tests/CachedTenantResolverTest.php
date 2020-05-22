<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Stancl\Tenancy\Resolvers\CachedTenantResolver;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;
use Stancl\Tenancy\Tests\Etc\Tenant;

class CachedTenantResolverTest extends TestCase
{
    /** @test */
    public function tenants_can_be_resolved_using_the_cached_resolver()
    {
        $tenant = Tenant::create();
        $tenant->domains()->create([
            'domain' => 'acme',
        ]);

        $this->assertTrue($tenant->is(app(DomainTenantResolver::class)->resolve('acme')));
        $this->assertTrue($tenant->is(app(CachedTenantResolver::class)->resolve(DomainTenantResolver::class, ['acme'])));
    }

    /** @test */
    public function the_underlying_resolver_is_not_touched_when_using_the_cached_resolver()
    {
        $tenant = Tenant::create();
        $tenant->domains()->create([
            'domain' => 'acme',
        ]);

        $this->assertTrue($tenant->is(app(CachedTenantResolver::class)->resolve(DomainTenantResolver::class, ['acme'])));

        $this->mock(DomainTenantResolver::class, function ($mock) {
            return $mock->shouldNotReceive('resolve');
        });

        $this->assertTrue($tenant->is(app(CachedTenantResolver::class)->resolve(DomainTenantResolver::class, ['acme'])));
    }
}
