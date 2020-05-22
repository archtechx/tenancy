<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;
use Stancl\Tenancy\Resolvers\CachedTenantResolver;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;
use Stancl\Tenancy\Tests\Etc\Tenant;

class CachedTenantResolverTest extends TestCase
{
    public function tearDown(): void
    {
        InitializeTenancyByDomain::$shouldCache = false;
        InitializeTenancyByRequestData::$shouldCache = false;

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

    /** @test */
    public function caching_can_be_configured_selectively_on_initialization_middleware()
    {
        InitializeTenancyByDomain::$shouldCache = true;

        $tenant = Tenant::create([
            'id' => 'acme',
        ]);
        $tenant->domains()->create([
            'domain' => 'acme.localhost',
        ]);

        Route::get('/foo', function () {
            return 'bar';
        })->middleware(InitializeTenancyByDomain::class);

        // create cache
        $this->get('http://acme.localhost/foo')
            ->assertSee('bar');
        
        $this->mock(CachedTenantResolver::class, function ($mock) {
            return $mock->shouldReceive('resolve')->once(); // only once
        });

        // use cache
        $this->get('http://acme.localhost/foo')
            ->assertSee('bar');

        Route::get('/abc', function () {
            return 'xyz';
        })->middleware(InitializeTenancyByRequestData::class);

        $this->get('/abc?tenant=acme')
            ->assertSee('xyz');

        $this->get('/abc?tenant=acme')
            ->assertSee('xyz');
    }
}
