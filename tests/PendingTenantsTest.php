<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Stancl\Tenancy\Commands\ClearPendingTenants;
use Stancl\Tenancy\Commands\CreatePendingTenants;
use Stancl\Tenancy\Events\CreatingPendingTenant;
use Stancl\Tenancy\Events\PendingTenantCreated;
use Stancl\Tenancy\Events\PendingTenantPulled;
use Stancl\Tenancy\Events\PullingPendingTenant;
use Stancl\Tenancy\Tests\Etc\Tenant;
use function Stancl\Tenancy\Tests\pest;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Stancl\Tenancy\Jobs\SeedDatabase;
use Stancl\Tenancy\Tests\Etc\User;
use Stancl\Tenancy\Tests\Etc\TestSeeder;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Listeners\RevertToCentralContext;

beforeEach($cleanup = function () {
    Tenant::$extraCustomColumns = [];
    Tenant::$getPendingAttributesUsing = null;

    MigrateDatabase::$includePending = true;
    SeedDatabase::$includePending = true;
});

afterEach($cleanup);

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

test('withoutPending chained with where clauses returns correct results', function () {
    $tenant = Tenant::create();
    $pendingTenant = Tenant::createPending();

    // The query returned the correct tenant
    expect(Tenant::withoutPending()->where('id', $tenant->id)->first()->id)->toBe($tenant->id);
    // No tenant with this ID exists, the query returns null
    expect(Tenant::withoutPending()->where('id', Str::random(8) . 'nonexistent-id')->first())->toBeNull();
    // withoutPending() correctly excludes the pending tenant from the query
    expect(Tenant::withoutPending()->where('id', $pendingTenant->id)->first())->toBeNull();
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

test('commands include tenants based on the include_in_queries config when --with-pending is not passed', function (bool $includeInQueries) {
    config(['tenancy.pending.include_in_queries' => $includeInQueries]);

    $tenants = collect([
        Tenant::create(),
        Tenant::create(),
        Tenant::createPending(),
        Tenant::createPending(),
    ]);

    $command = pest()->artisan("tenants:run 'bar testing testing@test.test password foo'");

    $tenants->each(function ($tenant) use ($command, $includeInQueries) {
        if ($tenant->pending() && ! $includeInQueries) {
            $command->doesntExpectOutputToContain("Tenant: {$tenant->getTenantKey()}");
        } else {
            $command->expectsOutputToContain("Tenant: {$tenant->getTenantKey()}");
        }
    });

    $command->assertSuccessful();
})->with([true, false]);

test('commands include pending tenants when truthy --with-pending is passed', function (bool $includeInQueries) {
    config(['tenancy.pending.include_in_queries' => $includeInQueries]);

    $tenants = collect([
        Tenant::create(),
        Tenant::create(),
        Tenant::createPending(),
        Tenant::createPending(),
    ]);

    foreach ([
        '--with-pending',
        '--with-pending=true',
        '--with-pending=1'
    ] as $option) {
        $command = pest()->artisan("tenants:run 'bar testing testing@test.test password foo' {$option}");

        // Pending tenants are included regardless of tenancy.pending.include_in_queries
        $tenants->each(fn ($tenant) => $command->expectsOutputToContain("Tenant: {$tenant->getTenantKey()}"));

        $command->assertSuccessful();
    }
})->with([true, false]);

test('commands exclude pending tenants when falsy --with-pending is passed', function (bool $includeInQueries) {
    config(['tenancy.pending.include_in_queries' => $includeInQueries]);

    $tenants = collect([
        Tenant::create(),
        Tenant::create(),
        Tenant::createPending(),
        Tenant::createPending(),
    ]);

    foreach ([
        '--with-pending=false',
        '--with-pending=0',
        '--with-pending=foo' // Invalid values are treated as false
    ] as $option) {
        $command = pest()->artisan("tenants:run 'bar testing testing@test.test password foo' {$option}");

        $tenants->each(function ($tenant) use ($command) {
            if ($tenant->pending()) {
                // Pending tenants are excluded regardless of tenancy.pending.include_in_queries
                $command->doesntExpectOutputToContain("Tenant: {$tenant->getTenantKey()}");
            } else {
                $command->expectsOutputToContain("Tenant: {$tenant->getTenantKey()}");
            }
        });

        $command->assertSuccessful();
    }
})->with([true, false]);

test('pending tenants can have default attributes for non-nullable columns', function (bool $withPendingAttributes) {
    Schema::table('tenants', function (Blueprint $table) {
        $table->string('slug')->unique();
    });

    Tenant::$extraCustomColumns = ['slug'];
    if ($withPendingAttributes) Tenant::$getPendingAttributesUsing = fn () => [
        'slug' => Str::random(8),
    ];

    $fn = fn () => Tenant::createPending();

    // If there are non-nullable custom columns, and createPending() is called
    // on its own without any values passed for those columns (as it would be called
    // by the tenants:pending-create artisan command), we expect it to fail, unless
    // getPendingAttributes() provides default values for those custom columns.
    if ($withPendingAttributes)
        expect($fn)->not()->toThrow(QueryException::class);
    else
        expect($fn)->toThrow(QueryException::class);
})->with([true, false]);

test('pending tenant databases can be migrated using a job unless configured otherwise', function (bool $includeInQueries, bool $migrateWithPending) {
    config([
        'tenancy.bootstrappers' => [DatabaseTenancyBootstrapper::class],
        'tenancy.pending.include_in_queries' => $includeInQueries,
    ]);

    MigrateDatabase::$includePending = $migrateWithPending;

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
    Event::listen(TenantCreated::class, JobPipeline::make([
        CreateDatabase::class,
        MigrateDatabase::class,
    ])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    $pendingTenant = Tenant::createPending();

    expect(Schema::hasTable('users'))->toBeFalse();

    tenancy()->initialize($pendingTenant);

    // MigrateDatabase includes/excludes pending tenants based on its $includePending property,
    // regardless of the tenancy.pending.include_in_queries config.
    expect(Schema::hasTable('users'))->toBe($migrateWithPending);
})->with([
    'include pending in queries' => [true],
    'exclude pending from queries' => [false],
])->with([
    'migrate with pending' => [true],
    'migrate without pending' => [false],
]);

test('pending tenant databases can be seeded using a job unless configured otherwise', function ($includeInQueries, $seedWithPending) {
    config([
        'tenancy.bootstrappers' => [DatabaseTenancyBootstrapper::class],
        'tenancy.pending.include_in_queries' => $includeInQueries,
        'tenancy.seeder_parameters.--class' => TestSeeder::class,
    ]);

    MigrateDatabase::$includePending = true;
    SeedDatabase::$includePending = $seedWithPending;

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
    Event::listen(TenantCreated::class, JobPipeline::make([
        CreateDatabase::class,
        MigrateDatabase::class,
        SeedDatabase::class,
    ])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    $pendingTenant = Tenant::createPending();

    tenancy()->initialize($pendingTenant);

    // SeedDatabase includes/excludes pending tenants based on its $includePending property,
    // regardless of the tenancy.pending.include_in_queries config.
    expect(User::where('email', 'seeded@user')->exists())->toBe($seedWithPending);
})->with([
    'include pending in queries' => [true],
    'exclude pending from queries' => [false],
])->with([
    'seed with pending' => [true],
    'seed without pending' => [false],
]);

test('jobs that run before tenants get fully created recognize pending tenants', function () {
    config([
        'tenancy.bootstrappers' => [DatabaseTenancyBootstrapper::class],
    ]);

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
    Event::listen(TenantCreated::class, JobPipeline::make([
        CreateDatabase::class,
        PendingTenantJob::class,
    ])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    Tenant::createPending();

    expect(app('tenant_is_pending'))->toBeTrue();
});

class PendingTenantJob
{
    public function __construct(
        public Tenant $tenant,
    ) {}

    public function handle()
    {
        app()->instance('tenant_is_pending', $this->tenant->pending());
    }
}
