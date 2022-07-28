<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Commands\ClearPendingTenants;
use Stancl\Tenancy\Commands\CreatePendingTenants;
use Stancl\Tenancy\Events\PullingPendingTenant;
use Stancl\Tenancy\Events\PendingTenantPulled;
use Stancl\Tenancy\Events\CreatingPendingTenant;
use Stancl\Tenancy\Events\PendingTenantCreated;
use Stancl\Tenancy\Tests\Etc\Tenant;

class PendingTenantsTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
    }

    /** @test */
    public function a_tenant_is_correctly_identified_as_pending()
    {
        Tenant::createPending();

        $this->assertCount(1, Tenant::onlyPending()->get());

        Tenant::onlyPending()->first()->update([
            'pending_since' => null
        ]);

        $this->assertCount(0, Tenant::onlyPending()->get());
    }

    /** @test */
    public function pending_trait_imports_query_scopes()
    {
        Tenant::createPending();
        Tenant::create();
        Tenant::create();

        $this->assertCount(1, Tenant::onlyPending()->get());

        $this->assertCount(3, Tenant::withPending(true)->get());

        $this->assertCount(2, Tenant::withPending(false)->get());

        $this->assertCount(2, Tenant::withoutPending()->get());
    }

    /** @test */
    public function pending_tenants_are_created_and_deleted_from_the_commands()
    {
        config(['tenancy.pending.count' => 4]);

        Artisan::call(CreatePendingTenants::class);

        $this->assertCount(4, Tenant::onlyPending()->get());

        Artisan::call(ClearPendingTenants::class);

        $this->assertCount(0, Tenant::onlyPending()->get());
    }

    /** @test */
    public function clear_pending_tenants_command_only_delete_pending_tenants_older_than()
    {
        config(['tenancy.pending.count' => 2]);

        Artisan::call(CreatePendingTenants::class);

        tenancy()->model()->query()->onlyPending()->first()->update([
            'pending_since' => now()->subDays(5)->timestamp
        ]);

        Artisan::call('tenants:pending-clear --older-than-days=2');

        $this->assertCount(1, Tenant::onlyPending()->get());
    }

    /** @test */
    public function clear_pending_tenants_command_cannot_run_with_both_time_constraints()
    {
        $this->artisan('tenants:pending-clear --older-than-days=2 --older-than-hours=2')
            ->assertFailed();
    }

    /** @test */
    public function clear_pending_tenants_command_all_option_overrides_config()
    {
        Tenant::createPending();
        Tenant::createPending();

        tenancy()->model()->query()->onlyPending()->first()->update([
            'pending_since' => now()->subDays(10)
        ]);

        config(['tenancy.pending.older_than_days' => 4]);

        Artisan::call(ClearPendingTenants::class, [
            '--all' => true
        ]);

        $this->assertCount(0, Tenant::onlyPending()->get());
    }

    /** @test */
    public function tenancy_can_check_for_rpending_tenants()
    {
        Tenant::query()->delete();

        $this->assertFalse(Tenant::onlyPending()->exists());

        Tenant::createPending();

        $this->assertTrue(Tenant::onlyPending()->exists());
    }

    /** @test */
    public function tenancy_can_pull_a_pending_tenant()
    {
        $this->assertNull(Tenant::pullPendingTenant());

        Tenant::createPending();

        $this->assertInstanceOf(Tenant::class, Tenant::pullPendingTenant(true));
    }

    /** @test */
    public function tenancy_can_create_if_none_are_pending()
    {
        $this->assertCount(0, Tenant::all());

        Tenant::pullPendingTenant(true);

        $this->assertCount(1, Tenant::all());
    }

    /** @test */
    public function pending_tenants_global_scope_config_can_include_or_exclude()
    {
        Tenant::createPending();

        config(['tenancy.pending.include_in_queries' => false]);

        $this->assertCount(0, Tenant::all());

        config(['tenancy.pending.include_in_queries' => true]);

        $this->assertCount(1, Tenant::all());
        Tenant::all();
    }

    /** @test */
    public function pending_events_are_triggerred()
    {
        Event::fake([
            CreatingPendingTenant::class,
            PendingTenantCreated::class,
            PullingPendingTenant::class,
            PendingTenantPulled::class,
        ]);

        Tenant::createPending();

        Event::assertDispatched(CreatingPendingTenant::class);
        Event::assertDispatched(PendingTenantCreated::class);

        Tenant::pullPendingTenant();

        Event::assertDispatched(PullingPendingTenant::class);
        Event::assertDispatched(PendingTenantPulled::class);
    }
}
