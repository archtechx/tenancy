<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Commands\ClearPendingTenants;
use Stancl\Tenancy\Commands\CreatePendingTenants;
use Stancl\Tenancy\Events\CreatingPendingTenant;
use Stancl\Tenancy\Events\PendingTenantCreated;
use Stancl\Tenancy\Events\PendingTenantPulled;
use Stancl\Tenancy\Events\PullingPendingTenant;
use Stancl\Tenancy\Tests\Etc\Tenant;

test('tenants are correctly identified as pending', function (){
    Tenant::createPending();

    expect(Tenant::onlyPending()->count())->toBe(1);

    Tenant::onlyPending()->first()->update([
        'pending_since' => null
    ]);

    expect(Tenant::onlyPending()->count())->toBe(0);
});

test('pending trait imports query scopes', function () {
    Tenant::createPending();
    Tenant::create();
    Tenant::create();

    expect(Tenant::onlyPending()->count())->toBe(1)
        ->and(Tenant::withPending(true)->count())->toBe(3)
        ->and(Tenant::withPending(false)->count())->toBe(2)
        ->and(Tenant::withoutPending()->count())->toBe(2);

});

test('pending tenants are created and deleted from the commands', function () {
    config(['tenancy.pending.count' => 4]);

    Artisan::call(CreatePendingTenants::class);

    expect(Tenant::onlyPending()->count())->toBe(4);

    Artisan::call(ClearPendingTenants::class);

    expect(Tenant::onlyPending()->count())->toBe(0);
});

test('clear pending tenants command only delete pending tenants older than', function () {
    config(['tenancy.pending.count' => 2]);

    Artisan::call(CreatePendingTenants::class);

    tenancy()->model()->query()->onlyPending()->first()->update([
        'pending_since' => now()->subDays(5)->timestamp
    ]);

    Artisan::call('tenants:pending-clear --older-than-days=2');

    expect(Tenant::onlyPending()->count())->toBe(1);
});

test('clear pending tenants command cannot run with both time constraints', function () {
    pest()->artisan('tenants:pending-clear --older-than-days=2 --older-than-hours=2')
        ->assertFailed();
});

test('clear pending tenants command all option overrides config', function () {
    Tenant::createPending();
    Tenant::createPending();

    tenancy()->model()->query()->onlyPending()->first()->update([
        'pending_since' => now()->subDays(10)
    ]);

    config(['tenancy.pending.older_than_days' => 4]);

    Artisan::call(ClearPendingTenants::class, [
        '--all' => true
    ]);

    expect(Tenant::onlyPending()->count())->toBe(0);
});

test('tenancy can check for pending tenants', function () {
    Tenant::query()->delete();

    expect(Tenant::onlyPending()->exists())->toBeFalse();

    Tenant::createPending();

    expect(Tenant::onlyPending()->exists())->toBeTrue();
});

test('tenancy can pull a pending tenant', function () {
    expect(Tenant::pullPendingTenant())->toBeNull();

    Tenant::createPending();

    expect(Tenant::pullPendingTenant(true))->toBeInstanceOf(Tenant::class);
});

test('tenancy can create if none are pending', function () {
    expect(Tenant::all()->count())->toBe(0);

    Tenant::pullPendingTenant(true);

    expect(Tenant::all()->count())->toBe(1);
});

test('pending tenants global scope config can include or exclude', function () {
    Tenant::createPending();

    config(['tenancy.pending.include_in_queries' => false]);

    expect(Tenant::all()->count())->toBe(0);

    config(['tenancy.pending.include_in_queries' => true]);

    expect(Tenant::all()->count())->toBe(1);
    Tenant::all();
});

test('pending events are triggerred', function () {
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
});
