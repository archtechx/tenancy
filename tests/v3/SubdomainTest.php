<?php

namespace Stancl\Tenancy\Tests\v3;

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Database\Models;
use Stancl\Tenancy\Database\Models\Concerns\HasDomains;
use Stancl\Tenancy\Exceptions\NotASubdomainException;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Tests\TestCase;

class SubdomainTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        Route::group([
            'middleware' => InitializeTenancyBySubdomain::class,
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
    public function onfail_logic_can_be_customized()
    {
        InitializeTenancyBySubdomain::$onFail = function () {
            return 'foo';
        };

        $this
            ->get('http://foo.localhost/foo/abc/xyz')
            ->assertSee('foo');
    }

    /** @test */
    public function localhost_is_not_a_valid_subdomain()
    {
        $this->expectException(NotASubdomainException::class);

        $this
            ->withoutExceptionHandling()
            ->get('http://localhost/foo/abc/xyz');
    }

    /** @test */
    public function ip_address_is_not_a_valid_subdomain()
    {
        $this->expectException(NotASubdomainException::class);

        $this
            ->withoutExceptionHandling()
            ->get('http://127.0.0.1/foo/abc/xyz');
    }

    /** @test */
    public function oninvalidsubdomain_logic_can_be_customized()
    {
        // in this case, we need to return a response instance
        // since a string would be treated as the subdomain
        InitializeTenancyBySubdomain::$onInvalidSubdomain = function () {
            return response('foo custom invalid subdomain handler');
        };

        $this
            ->withoutExceptionHandling()
            ->get('http://127.0.0.1/foo/abc/xyz')
            ->assertSee('foo custom invalid subdomain handler');
    }
}

class Tenant extends Models\Tenant
{
    use HasDomains;
}
