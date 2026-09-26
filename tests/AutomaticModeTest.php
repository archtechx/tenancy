<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Exception;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Tests\Etc\Tenant;

class AutomaticModeTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
        Event::listen(TenancyEnded::class, RevertToCentralContext::class);
    }

    #[Test]
    public function context_is_switched_when_tenancy_is_initialized()
    {
        config(['tenancy.bootstrappers' => [
            MyBootstrapper::class,
        ]]);

        $tenant = Tenant::create([
            'id' => 'acme',
        ]);

        tenancy()->initialize($tenant);

        $this->assertSame('acme', app('tenancy_initialized_for_tenant'));
    }

    #[Test]
    public function context_is_reverted_when_tenancy_is_ended()
    {
        $this->context_is_switched_when_tenancy_is_initialized();

        tenancy()->end();

        $this->assertSame(true, app('tenancy_ended'));
    }

    #[Test]
    public function context_is_switched_when_tenancy_is_reinitialized()
    {
        config(['tenancy.bootstrappers' => [
            MyBootstrapper::class,
        ]]);

        $tenant = Tenant::create([
            'id' => 'acme',
        ]);

        tenancy()->initialize($tenant);

        $this->assertSame('acme', app('tenancy_initialized_for_tenant'));

        $tenant2 = Tenant::create([
            'id' => 'foobar',
        ]);

        tenancy()->initialize($tenant2);

        $this->assertSame('foobar', app('tenancy_initialized_for_tenant'));
    }

    #[Test]
    public function central_helper_runs_callbacks_in_the_central_state()
    {
        tenancy()->initialize($tenant = Tenant::create());

        tenancy()->central(function () {
            $this->assertSame(null, tenant());
        });

        $this->assertSame($tenant, tenant());
    }

    #[Test]
    public function central_helper_returns_the_value_from_the_callback()
    {
        tenancy()->initialize(Tenant::create());

        $this->assertSame('foo', tenancy()->central(function () {
            return 'foo';
        }));
    }

    #[Test]
    public function central_helper_reverts_back_to_tenant_context()
    {
        tenancy()->initialize($tenant = Tenant::create());

        tenancy()->central(function () {
            //
        });

        $this->assertSame($tenant, tenant());
    }

    #[Test]
    public function central_helper_reverts_back_to_tenant_context_when_the_callback_throws()
    {
        tenancy()->initialize($tenant = Tenant::create());

        $thrown = null;

        try {
            tenancy()->central(fn () => throw new Exception('central callback failed'));
        } catch (Exception $exception) {
            $thrown = $exception;
        }

        $this->assertSame('central callback failed', $thrown?->getMessage());
        $this->assertSame($tenant, tenant());
    }

    #[Test]
    public function central_helper_doesnt_change_tenancy_state_when_called_in_central_context()
    {
        $this->assertFalse(tenancy()->initialized);
        $this->assertNull(tenant());

        tenancy()->central(function () {
            //
        });

        $this->assertFalse(tenancy()->initialized);
        $this->assertNull(tenant());
    }

    #[Test] #[TestWith([false])] #[TestWith([true])]
    public function tenant_run_reverts_to_the_original_context_when_the_callback_throws(bool $startInTenantContext)
    {
        $tenant = Tenant::create();
        $originalTenant = $startInTenantContext ? Tenant::create() : null;

        if ($originalTenant) {
            tenancy()->initialize($originalTenant);
        }

        $thrown = null;

        try {
            $tenant->run(fn () => throw new Exception('run callback failed'));
        } catch (Exception $exception) {
            $thrown = $exception;
        }

        $this->assertSame('run callback failed', $thrown?->getMessage());
        $this->assertSame($startInTenantContext, tenancy()->initialized);
        $this->assertSame($originalTenant, tenant());
    }

    #[Test] #[TestWith([false])] #[TestWith([true])]
    public function run_for_multiple_reverts_to_the_original_context_when_the_callback_throws(bool $startInTenantContext)
    {
        $tenants = [Tenant::create(), Tenant::create()];
        $originalTenant = $startInTenantContext ? Tenant::create() : null;

        if ($originalTenant) {
            tenancy()->initialize($originalTenant);
        }

        $thrown = null;

        try {
            tenancy()->runForMultiple($tenants, fn () => throw new Exception('runForMultiple callback failed'));
        } catch (Exception $exception) {
            $thrown = $exception;
        }

        $this->assertSame('runForMultiple callback failed', $thrown?->getMessage());
        $this->assertSame($startInTenantContext, tenancy()->initialized);
        $this->assertSame($originalTenant, tenant());
    }
}

class MyBootstrapper implements TenancyBootstrapper
{
    public function bootstrap(\Stancl\Tenancy\Contracts\Tenant $tenant)
    {
        app()->instance('tenancy_initialized_for_tenant', $tenant->getTenantKey());
    }

    public function revert()
    {
        app()->instance('tenancy_ended', true);
    }
}
