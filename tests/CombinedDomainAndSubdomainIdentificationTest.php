<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomainOrSubdomain;

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

        config(['tenancy.tenant_model' => CombinedTenant::class]);
    }

    /** @test */
    public function tenant_can_be_identified_by_subdomain()
    {
        config(['tenancy.central_domains' => ['localhost']]);

        $tenant = CombinedTenant::create([
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

        $tenant = CombinedTenant::create([
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

class CombinedTenant extends Models\Tenant
{
    use HasDomains;
}
