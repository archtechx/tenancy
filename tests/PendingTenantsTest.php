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
use function Stancl\Tenancy\Tests\pest;

test('tenants are correctly identified as pending', function (){
    Tenant::createPending();

    expect(Tenant::onlyPending()->count())->toBe(1);

    Tenant::onlyPending()->first()->update([
        'pending_since' => null
    ]);

    expect(Tenant::onlyPending()->count())->toBe(0);
});

test('pending trait adds query scopes', function () {
    Tenant::createPending();
    Tenant::create();
    Tenant::create();

    expect(Tenant::onlyPending()->count())->toBe(1)
        ->and(Tenant::withPending(true)->count())->toBe(3)
        ->and(Tenant::withPending(false)->count())->toBe(2)
        ->and(Tenant::withoutPending()->count())->toBe(2);

});

test('pending tenants can be created and deleted using commands', function () {
    config(['tenancy.pending.count' => 4]);

    Artisan::call(CreatePendingTenants::class);

    expect(Tenant::onlyPending()->count())->toBe(4);

    Artisan::call(ClearPendingTenants::class);

    expect(Tenant::onlyPending()->count())->toBe(0);
});

test('CreatePendingTenants command can have an older than constraint', function () {
    config(['tenancy.pending.count' => 2]);

    Artisan::call(CreatePendingTenants::class);

    tenancy()->model()->query()->onlyPending()->first()->update([
        'pending_since' => now()->subDays(5)->timestamp
    ]);

    Artisan::call('tenants:pending-clear --older-than-days=2');

    expect(Tenant::onlyPending()->count())->toBe(1);
});

test('CreatePendingTenants command cannot run with both time constraints', function () {
    pest()->artisan('tenants:pending-clear --older-than-days=2 --older-than-hours=2')
        ->assertFailed();
});

test('tenancy can check if there are any pending tenants', function () {
    expect(Tenant::onlyPending()->exists())->toBeFalse();

    Tenant::createPending();

    expect(Tenant::onlyPending()->exists())->toBeTrue();
});

test('tenancy can pull a pending tenant', function () {
    Tenant::createPending();

    expect(Tenant::pullPendingFromPool())->toBeInstanceOf(Tenant::class);
});

test('pulling a tenant from the pending tenant pool removes it from the pool', function () {
    Tenant::createPending();

    expect(Tenant::onlyPending()->count())->toEqual(1);

    Tenant::pullPendingFromPool();

    expect(Tenant::onlyPending()->count())->toEqual(0);
});

test('a new tenant gets created while pulling a pending tenant if the pending pool is empty', function () {
    expect(Tenant::withPending()->get()->count())->toBe(0); // All tenants

    Tenant::pullPending();

    expect(Tenant::withPending()->get()->count())->toBe(1); // All tenants
});

test('pending tenants are included in all queries based on the include_in_queries config', function () {
    Tenant::createPending();

    config(['tenancy.pending.include_in_queries' => false]);

    expect(Tenant::all()->count())->toBe(0);

    config(['tenancy.pending.include_in_queries' => true]);

    expect(Tenant::all()->count())->toBe(1);
});

test('pending events are dispatched', function () {
    Event::fake([
        CreatingPendingTenant::class,
        PendingTenantCreated::class,
        PullingPendingTenant::class,
        PendingTenantPulled::class,
    ]);

    Tenant::createPending();

    Event::assertDispatched(CreatingPendingTenant::class);
    Event::assertDispatched(PendingTenantCreated::class);

    Tenant::pullPending();

    Event::assertDispatched(PullingPendingTenant::class);
    Event::assertDispatched(PendingTenantPulled::class);
});

test('commands do not run for pending tenants if tenancy.pending.include_in_queries is false and the with pending option does not get passed', function() {
    config(['tenancy.pending.include_in_queries' => false]);

    $tenants = collect([
        Tenant::create(),
        Tenant::create(),
        Tenant::createPending(),
        Tenant::createPending(),
    ]);

    pest()->artisan('tenants:migrate --with-pending');

    $artisan = pest()->artisan("tenants:run 'foo foo --b=bar --c=xyz'");

    $pendingTenants = $tenants->filter->pending();
    $readyTenants = $tenants->reject->pending();

    $pendingTenants->each(fn ($tenant) => $artisan->doesntExpectOutputToContain("Tenant: {$tenant->getTenantKey()}"));
    $readyTenants->each(fn ($tenant) => $artisan->expectsOutputToContain("Tenant: {$tenant->getTenantKey()}"));

    $artisan->assertExitCode(0);
});

test('commands run for pending tenants too if tenancy.pending.include_in_queries is true', function() {
    config(['tenancy.pending.include_in_queries' => true]);

    $tenants = collect([
        Tenant::create(),
        Tenant::create(),
        Tenant::createPending(),
        Tenant::createPending(),
    ]);

    pest()->artisan('tenants:migrate --with-pending');

    $artisan = pest()->artisan("tenants:run 'foo foo --b=bar --c=xyz'");

    $tenants->each(fn ($tenant) => $artisan->expectsOutputToContain("Tenant: {$tenant->getTenantKey()}"));

    $artisan->assertExitCode(0);
});

test('commands run for pending tenants too if the with pending option is passed', function() {
    config(['tenancy.pending.include_in_queries' => false]);

    $tenants = collect([
        Tenant::create(),
        Tenant::create(),
        Tenant::createPending(),
        Tenant::createPending(),
    ]);

    pest()->artisan('tenants:migrate --with-pending');

    $artisan = pest()->artisan("tenants:run 'foo foo --b=bar --c=xyz' --with-pending");

    $tenants->each(fn ($tenant) => $artisan->expectsOutputToContain("Tenant: {$tenant->getTenantKey()}"));

    $artisan->assertExitCode(0);
});
