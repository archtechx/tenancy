<?php

namespace Stancl\Tenancy\Tests\v3;

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Database\Models;
use Stancl\Tenancy\Database\Models\Concerns\HasDomains;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomainOrSubdomain;
use Stancl\Tenancy\Tests\TestCase;

class CombinedDomainAndSubdomainIdentificationTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        Route::group([
            'middleware' => InitializeTenancyByDomainOrSubdomain::class,
        ], function () {
            Route::get('/foo/{a}/{b}', function ($a, $b) {
                return "$a + $b";
            });
        });

        config(['tenancy.tenant_model' => Tenant::class]);
    }

    /** @test */
    public function tenant_can_be_identified_by_subdomain()
    {
        config(['tenancy.central_domains' => ['localhost']]);

        $tenant = Tenant::create([
            'id' => 'acme',
        ]);

        $tenant->domains()->create([
            'domain' => 'foo',
        ]);

        $this->assertFalse(tenancy()->initialized);

        $this
            ->get('http://foo.localhost/foo/abc/xyz')
            ->assertSee('abc + xyz');

        $this->assertTrue(tenancy()->initialized);
        $this->assertSame('acme', tenant('id'));
    }

    /** @test */
    public function tenant_can_be_identified_by_domain()
    {
        config(['tenancy.central_domains' => []]);

        $tenant = Tenant::create([
            'id' => 'acme',
        ]);

        $tenant->domains()->create([
            'domain' => 'foobar.localhost',
        ]);

        $this->assertFalse(tenancy()->initialized);

        $this
            ->get('http://foobar.localhost/foo/abc/xyz')
            ->assertSee('abc + xyz');

        $this->assertTrue(tenancy()->initialized);
        $this->assertSame('acme', tenant('id'));
    }
}

class Tenant extends Models\Tenant
{
    use HasDomains;
}
