<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Exceptions\TenancyNotInitializedException;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Middleware\ScopeSessions;
use Stancl\Tenancy\Tests\Etc\Tenant;

class ScopeSessionsTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        Route::group([
            'middleware' => [StartSession::class, InitializeTenancyBySubdomain::class, ScopeSessions::class],
        ], function () {
            Route::get('/foo', function () {
                return 'true';
            });
        });

        Event::listen(TenantCreated::class, function (TenantCreated $event) {
            $tenant = $event->tenant;

            /** @var Tenant $tenant */
            $tenant->domains()->create([
                'domain' => $tenant->id,
            ]);
        });
    }

    /** @test */
    public function tenant_id_is_auto_added_to_session_if_its_missing()
    {
        $tenant = Tenant::create([
            'id' => 'acme',
        ]);

        $this->get('http://acme.localhost/foo')
            ->assertSessionHas(ScopeSessions::$tenantIdKey, 'acme');
    }

    /** @test */
    public function changing_tenant_id_in_session_will_abort_the_request()
    {
        $tenant = Tenant::create([
            'id' => 'acme',
        ]);

        $this->get('http://acme.localhost/foo')
            ->assertSuccessful();

        session()->put(ScopeSessions::$tenantIdKey, 'foobar');

        $this->get('http://acme.localhost/foo')
            ->assertStatus(403);
    }

    /** @test */
    public function an_exception_is_thrown_when_the_middleware_is_executed_before_tenancy_is_initialized()
    {
        Route::get('/bar', function () {
            return true;
        })->middleware([StartSession::class, ScopeSessions::class]);

        $tenant = Tenant::create([
            'id' => 'acme',
        ]);

        $this->expectException(TenancyNotInitializedException::class);
        $this->withoutExceptionHandling()->get('http://acme.localhost/bar');
    }
}
