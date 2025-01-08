<?php

declare(strict_types=1);

use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Tests\Etc\User;
use Illuminate\Support\Str;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;

beforeEach(function () {
    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);

    Event::listen(TenantCreated::class, JobPipeline::make([
        CreateDatabase::class,
        MigrateDatabase::class,
    ])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    config(['tenancy.bootstrappers' => [
        DatabaseTenancyBootstrapper::class,
    ]]);
});

test('runForMultiple runs the passed closure for the right tenants', function() {
    $tenants = [Tenant::create(), Tenant::create(), Tenant::create()];

    $createUser = fn ($username) => function () use ($username) {
        User::create(['name' => $username, 'email' => Str::random(8) . '@example.com', 'password' => bcrypt('password')]);
    };

    // tenancy()->runForMultiple([], ...) shouldn't do anything
    // No users should be created -- the closure should not run at all
    tenancy()->runForMultiple([], $createUser('none'));
    // Try the same with an empty collection -- the result should be the same for any traversable
    tenancy()->runForMultiple(collect(), $createUser('none'));

    foreach ($tenants as $tenant) {
        $tenant->run(function() {
            expect(User::count())->toBe(0);
        });
    }

    // tenancy()->runForMultiple(['foo', 'bar'], ...) should run the closure only for the passed tenants
    tenancy()->runForMultiple([$tenants[0]->getTenantKey(), $tenants[1]->getTenantKey()], $createUser('user'));

    // User should be created for tenants[0] and tenants[1], but not for tenants[2]
    foreach ($tenants as $tenant) {
        $tenant->run(function() use ($tenants) {
            if (tenant()->getTenantKey() !== $tenants[2]->getTenantKey()) {
                expect(User::first()->name)->toBe('user');
            } else {
                expect(User::count())->toBe(0);
            }
        });
    }

    // tenancy()->runForMultiple(null, ...) should run the closure for all tenants
    tenancy()->runForMultiple(null, $createUser('new_user'));

    foreach ($tenants as $tenant) {
        $tenant->run(function() {
            expect(User::all()->pluck('name'))->toContain('new_user');
        });
    }
});
