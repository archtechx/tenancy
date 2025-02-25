<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Stancl\JobPipeline\JobPipeline;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Events\TenantCreated;
use Illuminate\Events\CallQueuedListener;
use Stancl\Tenancy\Database\DatabaseConfig;
use Stancl\Tenancy\ResourceSyncing\Syncable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\ResourceSyncing\SyncMaster;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Stancl\Tenancy\ResourceSyncing\ResourceSyncing;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\ResourceSyncing\TenantMorphPivot;
use Stancl\Tenancy\Tests\Etc\ResourceSyncing\Tenant;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Stancl\Tenancy\Database\Concerns\CentralConnection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Stancl\Tenancy\ResourceSyncing\Events\SyncMasterDeleted;
use Stancl\Tenancy\ResourceSyncing\TenantPivot as BasePivot;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\ResourceSyncing\Events\SyncMasterRestored;
use Stancl\Tenancy\ResourceSyncing\Events\SyncedResourceSaved;
use Stancl\Tenancy\ResourceSyncing\ModelNotSyncMasterException;
use Stancl\Tenancy\ResourceSyncing\Listeners\CreateTenantResource;
use Stancl\Tenancy\ResourceSyncing\Listeners\DeleteResourceInTenant;
use Stancl\Tenancy\ResourceSyncing\Listeners\DeleteResourcesInTenants;
use Stancl\Tenancy\ResourceSyncing\Listeners\RestoreResourcesInTenants;
use Stancl\Tenancy\ResourceSyncing\Events\CentralResourceAttachedToTenant;
use Stancl\Tenancy\ResourceSyncing\Listeners\UpdateOrCreateSyncedResource;
use Stancl\Tenancy\Tests\Etc\ResourceSyncing\TenantUser as BaseTenantUser;
use Stancl\Tenancy\ResourceSyncing\Events\CentralResourceDetachedFromTenant;
use Stancl\Tenancy\Tests\Etc\ResourceSyncing\CentralUser as BaseCentralUser;
use Stancl\Tenancy\ResourceSyncing\CentralResourceNotAvailableInPivotException;
use Stancl\Tenancy\ResourceSyncing\Events\SyncedResourceSavedInForeignDatabase;
use function Stancl\Tenancy\Tests\pest;

beforeEach(function () {
    config(['tenancy.bootstrappers' => [
        DatabaseTenancyBootstrapper::class,
    ]]);

    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    DatabaseConfig::generateDatabaseNamesUsing(function () {
        return 'db' . Str::random(16);
    });

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);

    // Global state cleanup
    UpdateOrCreateSyncedResource::$shouldQueue = false;
    DeleteResourcesInTenants::$shouldQueue = false;
    CreateTenantResource::$shouldQueue = false;
    DeleteResourceInTenant::$shouldQueue = false;
    UpdateOrCreateSyncedResource::$scopeGetModelQuery = null;

    $syncedAttributes = [
        'global_id',
        'name',
        'password',
        'email',
    ];

    TenantUser::$shouldSync = true;
    CentralUser::$shouldSync = true;

    TenantUser::$syncedAttributes = $syncedAttributes;
    CentralUser::$syncedAttributes = $syncedAttributes;

    $creationAttributes = ['role' => 'commenter'];

    TenantUser::$creationAttributes = $creationAttributes;
    CentralUser::$creationAttributes = $creationAttributes;

    Event::listen(SyncedResourceSaved::class, UpdateOrCreateSyncedResource::class);
    Event::listen(SyncMasterDeleted::class, DeleteResourcesInTenants::class);
    Event::listen(SyncMasterRestored::class, RestoreResourcesInTenants::class);
    Event::listen(CentralResourceAttachedToTenant::class, CreateTenantResource::class);
    Event::listen(CentralResourceDetachedFromTenant::class, DeleteResourceInTenant::class);

    // Run migrations on central connection
    pest()->artisan('migrate', [
        '--path' => [
            __DIR__ . '/../assets/resource-syncing-migrations',
            __DIR__ . '/Etc/synced_resource_migrations',
            __DIR__ . '/Etc/synced_resource_migrations/users',
            __DIR__ . '/Etc/synced_resource_migrations/companies',
        ],
        '--realpath' => true,
    ])->assertExitCode(0);
});

afterEach(function () {
    UpdateOrCreateSyncedResource::$scopeGetModelQuery = null;
});

test('SyncedResourceSaved event gets triggered when resource gets created or when its synced attributes get updated', function () {
    Event::fake(SyncedResourceSaved::class);

    // Create resource
    $user = TenantUser::create([
        'name' => 'Foo',
        'email' => 'foo@email.com',
        'password' => 'secret',
        'global_id' => 'foo',
        'role' => 'foo',
    ]);

    Event::assertDispatched(SyncedResourceSaved::class, function (SyncedResourceSaved $event) use ($user) {
        return $event->model === $user;
    });

    // Flush
    Event::fake(SyncedResourceSaved::class);

    // Update resource's synced attribute
    $user->update(['name' => 'Bar']);

    Event::assertDispatched(SyncedResourceSaved::class, function (SyncedResourceSaved $event) use ($user) {
        return $event->model === $user;
    });

    // Flush
    Event::fake(SyncedResourceSaved::class);

    // Refetch the model to reset $user->wasRecentlyCreated
    $user = TenantUser::firstWhere('name', 'Bar');

    // Update resource's unsynced attribute
    $user->update(['role' => 'bar']);

    Event::assertNotDispatched(SyncedResourceSaved::class); // regression test for #1168
});

test('only synced columns get updated by the syncing logic', function () {
    // Create user in central DB
    $user = CentralUser::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'superadmin', // Unsynced
    ]);

    $tenant = Tenant::create();
    migrateUsersTableForTenants();

    tenancy()->initialize($tenant);

    // Create the same user in tenant DB
    $user = TenantUser::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter', // Unsynced
    ]);

    // Update user in tenant DB
    $user->update([
        'name' => 'John Foo', // Synced
        'email' => 'john@foreignhost', // Synced
        'role' => 'admin', // Unsynced
    ]);

    tenancy()->end();

    // Assert changes bubbled up
    pest()->assertEquals([
        'id' => 1,
        'global_id' => 'acme',
        'name' => 'John Foo', // Synced
        'email' => 'john@foreignhost', // Synced
        'password' => 'secret',
        'role' => 'superadmin', // Unsynced
    ], TenantUser::first()->getAttributes());
});

test('updating tenant resources from central context throws an exception', function () {
    $tenant = Tenant::create();
    migrateUsersTableForTenants();

    tenancy()->initialize($tenant);

    TenantUser::create([
        'global_id' => 'foo',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter',
    ]);

    tenancy()->end();

    pest()->expectException(ModelNotSyncMasterException::class);
    TenantUser::first()->update(['password' => 'foobar']);
});

test('attaching central resources to tenants or vice versa creates synced tenant resource', function () {
    $createCentralUser = fn () => CentralUser::create([
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter', // Unsynced
    ]);

    $tenant = Tenant::create();

    migrateUsersTableForTenants();

    $tenant->run(function () {
        expect(TenantUser::all())->toHaveCount(0);
    });

    // Attaching resources to tenants requires using a pivot that implements the PivotWithRelation interface
    $tenant->customPivotUsers()->attach($createCentralUser());
    $createCentralUser()->tenants()->attach($tenant);

    $tenant->run(function () {
        // two (separate) central users were created, so there are now two separate tenant users in the tenant's database
        expect(TenantUser::all())->toHaveCount(2);
    });
});

test('detaching central users from tenants or vice versa force deletes the synced tenant resource', function (bool $attachUserToTenant) {
    $centralUser = CentralUser::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter', // Unsynced
    ]);

    $tenant = Tenant::create();

    migrateUsersTableForTenants();

    if ($attachUserToTenant) {
        // Attaching resources to tenants requires using a pivot that implements the PivotWithRelation interface
        $tenant->customPivotUsers()->attach($centralUser);
    } else {
        $centralUser->tenants()->attach($tenant);
    }

    $tenant->run(function () {
        expect(TenantUser::all())->toHaveCount(1);
    });

    if ($attachUserToTenant) {
        // Detaching resources from tenants requires using a pivot that implements the PivotWithRelation interface
        $tenant->customPivotUsers()->detach($centralUser);
    } else {
        $centralUser->tenants()->detach($tenant);
    }

    $tenant->run(function () {
        expect(TenantUser::all())->toHaveCount(0);
    });

    addExtraColumns(true);

    // Detaching *force deletes* the tenant resource
    CentralUserWithSoftDeletes::$creationAttributes = ['role' => 'commenter', 'foo' => 'bar'];

    $centralUserWithSoftDeletes = CentralUserWithSoftDeletes::create([
        'global_id' => 'bar',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter', // Unsynced
        'foo' => 'bar',
    ]);

    if ($attachUserToTenant) {
        $tenant->customPivotUsers()->attach($centralUserWithSoftDeletes);
    } else {
        $centralUserWithSoftDeletes->tenants()->attach($tenant);
    }

    $tenant->run(function () {
        expect(TenantUserWithSoftDeletes::withTrashed()->count())->toBe(1);
    });

    if ($attachUserToTenant) {
        // Detaching resources from tenants requires using a pivot that implements the PivotWithRelation interface
        $tenant->customPivotUsers()->detach($centralUserWithSoftDeletes);
    } else {
        $centralUserWithSoftDeletes->tenants()->detach($tenant);
    }

    $tenant->run(function () {
        expect(TenantUserWithSoftDeletes::withTrashed()->count())->toBe(0);
    });
})->with([
    true,
    false,
]);

test('attaching tenant to central resource works correctly even when using a single pivot table for multiple models', function () {
    config(['tenancy.models.tenant' => MorphTenant::class]);

    [$tenant1, $tenant2] = createTenantsAndRunMigrations();

    migrateCompaniesTableForTenants();

    // Use BaseCentralUser and BaseTenantUser in this test
    // These models use the polymorphic relationship for tenants(), which is the default
    $centralUser = BaseCentralUser::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'password',
        'role' => 'commenter',
    ]);

    $tenant1->run(function () {
        expect(BaseTenantUser::count())->toBe(0);
    });

    // Create tenant resource from central resource
    // By attaching a tenant to the central resource
    $centralUser->tenants()->attach($tenant1);

    // Central users are accessible from the tenant using the relationship method
    expect($tenant1->users()->count())->toBe(1);

    // Tenants are accessible from the central resource using the relationship method
    expect($centralUser->tenants()->count())->toBe(1);

    // The tenant resource got created with the correct attributes
    $tenant1->run(function () use ($centralUser) {
        $tenantUser = BaseTenantUser::first()->getAttributes();
        $centralUser = $centralUser->getAttributes();

        expect($tenantUser)->toEqualCanonicalizing($centralUser);
    });

    // Test that the company resource can use the same pivot as the user resource
    $centralCompany = CentralCompany::create([
        'global_id' => 'acme',
        'name' => 'ArchTech',
        'email' => 'archtech@localhost',
    ]);

    // Central company wasn't attached yet
    $tenant2->run(function () {
        expect(TenantCompany::count())->toBe(0);
    });

    $centralCompany->tenants()->attach($tenant2);

    // Tenant company got created during the attaching
    $tenant2->run(function () {
        expect(TenantCompany::count())->toBe(1);
    });

    // The tenant companies are accessible from tenant using the relationship method
    expect($tenant2->companies()->count())->toBe(1);

    // Tenants are accessible from the central resource using the relationship method
    expect($centralCompany->tenants()->count())->toBe(1);

    // The TenantCompany resource got created with the correct attributes
    $tenant2->run(function () use ($centralCompany) {
        $tenantCompany = TenantCompany::first()->getAttributes();
        $centralCompany = $centralCompany->getAttributes();

        expect($tenantCompany)->toEqualCanonicalizing($centralCompany);
    });


    // Detaching tenant from a central resource deletes the resource of that tenant
    $centralUser->tenants()->detach($tenant1);
    $tenant1->run(function () {
        expect(BaseTenantUser::count())->toBe(0);
    });

    $centralUser->tenants()->attach($tenant2);

    // Detaching tenant from a central resource doesn't affect resources of other tenants
    $tenant2->run(function () {
        expect(BaseTenantUser::count())->toBe(1);
    });

    $centralCompany->tenants()->detach($tenant2);

    $tenant1->run(function () {
        expect(TenantCompany::count())->toBe(0);
    });
});

test('attaching central resource to tenant works correctly even when using a single pivot table for multiple models', function () {
    config(['tenancy.models.tenant' => MorphTenant::class]);

    [$tenant1, $tenant2] = createTenantsAndRunMigrations();

    // Use BaseCentralUser and BaseTenantUser in this test
    // These models use the polymorphic relationship for tenants(), which is the default
    $centralUser = BaseCentralUser::create([
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'password',
        'role' => 'commenter',
    ]);

    expect(DB::table('tenant_resources')->count())->toBe(0);

    // Create tenant resource from central resource
    // By attaching a central resource to the tenant
    $tenant1->users()->attach($centralUser);

    // The tenant resource got created
    // And with the correct attributes
    $tenantUser = $tenant1->run(function () {
        return BaseTenantUser::first();
    });

    expect($tenantUser?->getAttributes())->toEqualCanonicalizing($centralUser->getAttributes());

    // Tenants are accessible from the central resource using the relationship method
    expect($centralUser->tenants()->count())->toBe(1);
    expect(DB::table('tenant_resources')->count())->toBe(1);

    // Central users are accessible from the tenant using the relationship method
    expect($tenant1->users()->count())->toBe(1);

    $tenant1->users()->detach($centralUser);

    $tenant1->run(function () {
        expect(BaseTenantUser::count())->toBe(0);
    });

    expect(DB::table('tenant_resources')->count())->toBe(0);

    // Test that the company resource can use the same pivot as the user resource
    migrateCompaniesTableForTenants();
    expect($tenant2->companies()->count())->toBe(0);
    expect(DB::table('tenant_resources')->count())->toBe(0);

    // Company resource uses the same pivot as the user resource
    // Creating a tenant resource creates the central resource automatically if it doesn't exist
    $tenantCompany = $tenant2->run(function () {
        return TenantCompany::create([
            'global_id' => 'acme',
            'name' => 'tenant comp',
            'email' => 'company@localhost',
        ]);
    });

    $centralCompany = CentralCompany::first();

    // The central resource got created
    // And with the correct attributes
    expect($centralCompany?->getAttributes())->toEqualCanonicalizing($tenantCompany->getAttributes());

    // The pivot record got created
    expect(DB::table('tenant_resources')->count())->toBe(1);

    // Tenants are accessible from the central resource using the relationship method
    expect($centralCompany->tenants()->count())->toBe(1);

    // Central companies are accessible from the tenant using the relationship method
    expect($tenant2->companies()->count())->toBe(1);

    // Detaching central resource from a tenant deletes the resource of that tenant
    $tenant2->companies()->detach($centralCompany);

    expect($tenant2->companies()->count())->toBe(0);

    // Tenant resource got deleted
    $tenant2->run(function () {
        expect(TenantCompany::count())->toBe(0);
    });
});

test('attaching or detaching users to or from tenant throws an exception when the pivot cannot access the central resource', function() {
    $tenant = Tenant::create();

    migrateUsersTableForTenants();

    $centralUser = CentralUser::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter',
    ]);

    expect(TenantPivot::count())->toBe(0);

    expect(fn () => $tenant->users()->attach($centralUser))->toThrow(CentralResourceNotAvailableInPivotException::class);
    expect(TenantPivot::count())->toBe(0);

    $centralUser->tenants()->attach($tenant); // central->tenants() direction works
    expect(TenantPivot::count())->toBe(1);

    expect(fn () => $tenant->users()->detach($centralUser))->toThrow(CentralResourceNotAvailableInPivotException::class);
    expect(TenantPivot::count())->toBe(1);
});

test('resources only get created in tenant databases they were attached to', function () {
    $centralUser = CentralUser::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter', // Unsynced
    ]);

    $t1 = Tenant::create();
    $t2 = Tenant::create();
    $t3 = Tenant::create();

    migrateUsersTableForTenants();

    $centralUser->tenants()->attach($t1);
    $centralUser->tenants()->attach($t2);
    // t3 is not attached

    $t1->run(fn () => expect(TenantUser::count())->toBe(1)); // assert user exists
    $t2->run(fn () => expect(TenantUser::count())->toBe(1)); // assert user exists
    $t3->run(fn () => expect(TenantUser::count())->toBe(0)); // assert user does NOT exist
});

test('synced columns are updated in other tenant dbs where the resource exists', function () {
    // Create central resource
    $centralUser = CentralUser::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter', // Unsynced
    ]);

    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();
    $tenant3 = Tenant::create();
    migrateUsersTableForTenants();

    // Create tenant users by attaching tenants to the central user
    $centralUser->tenants()->attach($tenant1);
    $centralUser->tenants()->attach($tenant2);
    $centralUser->tenants()->attach($tenant3);

    // Update first tenant's resource
    $tenant1->run(function () {
        TenantUser::first()->update([
            'name' => 'John 1',
            'role' => 'employee', // Unsynced
        ]);

        expect(TenantUser::first()->role)->toBe('employee');
    });

    // Check that the resources of the other tenants got updated too
    tenancy()->runForMultiple([$tenant2, $tenant3], function () {
        $user = TenantUser::first();

        expect($user->name)->toBe('John 1');
        expect($user->role)->toBe('commenter');
    });

    // Check that change bubbled up to central DB
    expect(CentralUser::count())->toBe(1);
    $centralUser = CentralUser::first();
    expect($centralUser->name)->toBe('John 1'); // Synced
    expect($centralUser->role)->toBe('commenter'); // Unsynced

    // This works when the change comes from the central DB – all tenant resources get updated
    $centralUser->update(['name' => 'John 0']);

    tenancy()->runForMultiple([$tenant1, $tenant2, $tenant3], function () {
        expect(TenantUser::first()->name)->toBe('John 0');
    });
});

test('the global id is generated using the id generator when the global id is not supplied when creating the resource', function () {
    $user = CentralUser::create([
        'name' => 'John Doe',
        'email' => 'john@doe',
        'password' => 'secret',
        'role' => 'employee',
    ]);

    pest()->assertNotNull($user->global_id);
});

test('the update or create listener can be queued', function () {
    Queue::fake();
    UpdateOrCreateSyncedResource::$shouldQueue = true;

    $tenant = Tenant::create();

    migrateUsersTableForTenants();

    Queue::assertNothingPushed();

    $tenant->run(function () {
        TenantUser::create([
            'name' => 'John Doe',
            'email' => 'john@doe',
            'password' => 'secret',
            'role' => 'employee',
        ]);
    });

    Queue::assertPushed(CallQueuedListener::class, function (CallQueuedListener $job) {
        return $job->class === UpdateOrCreateSyncedResource::class;
    });

    UpdateOrCreateSyncedResource::$shouldQueue = false;
});

test('the cascade deletes and restore listeners can be queued', function () {
    Queue::fake();
    DeleteResourcesInTenants::$shouldQueue = true;
    RestoreResourcesInTenants::$shouldQueue = true;
    CentralUserWithSoftDeletes::$creationAttributes = [
        'foo' => 'foo',
        'role' => 'role',
    ];

    $tenant = Tenant::create();

    migrateUsersTableForTenants();
    addExtraColumns(true);

    Queue::assertNothingPushed();

    $centralUser = fn () => CentralUserWithSoftDeletes::create([
        'name' => 'John Doe',
        'email' => 'john@doe',
        'password' => 'secret',
        'role' => 'employee',
        'foo' => 'foo',
    ]);

    [$user1, $user2] = [$centralUser(), $centralUser()];

    $tenant->softDeletesUsers()->attach($user1); // Custom pivot method
    $user2->tenants()->attach($tenant);

    $user1->delete();
    $user2->delete();

    Queue::assertPushed(CallQueuedListener::class, function (CallQueuedListener $job) {
        return $job->class === DeleteResourcesInTenants::class;
    });

    $user1->restore();
    $user2->restore();

    Queue::assertPushed(CallQueuedListener::class, function (CallQueuedListener $job) {
        return $job->class === RestoreResourcesInTenants::class;
    });

    DeleteResourcesInTenants::$shouldQueue = false;
    RestoreResourcesInTenants::$shouldQueue = false;
});

test('the attach and detach listeners can be queued', function () {
    Queue::fake();
    CreateTenantResource::$shouldQueue = true;
    DeleteResourceInTenant::$shouldQueue = true;

    $tenant = Tenant::create();

    migrateUsersTableForTenants();

    $centralUser = fn () => CentralUser::create([
        'name' => 'John Doe',
        'email' => 'john@doe',
        'password' => 'secret',
        'role' => 'employee',
    ]);

    $user1 = $centralUser();
    $user2 = $centralUser();

    $tenant->customPivotUsers()->attach($user1);
    $user2->tenants()->attach($tenant);

    Queue::assertPushed(CallQueuedListener::class, function (CallQueuedListener $job) {
        return $job->class === CreateTenantResource::class;
    });

    $tenant->customPivotUsers()->detach($user1);
    $user2->tenants()->detach($tenant);

    Queue::assertPushed(CallQueuedListener::class, function (CallQueuedListener $job) {
        return $job->class === DeleteResourceInTenant::class;
    });

    CreateTenantResource::$shouldQueue = false;
    DeleteResourceInTenant::$shouldQueue = false;
});

test('the SyncedResourceSavedInForeignDatabase event is fired for all touched resources', function () {
    Event::fake([SyncedResourceSavedInForeignDatabase::class]);

    // Create central resource
    $centralUser = CentralUser::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter', // Unsynced
    ]);

    $t1 = Tenant::create(['id' => 't1']);
    $t2 = Tenant::create(['id' => 't2']);
    $t3 = Tenant::create(['id' => 't3']);

    migrateUsersTableForTenants();

    // Create tenant resources by attaching tenants to the central user
    foreach ([$t1, $t2, $t3] as $tenant) {
        $centralUser->tenants()->attach($tenant);

        Event::assertDispatched(SyncedResourceSavedInForeignDatabase::class, function (SyncedResourceSavedInForeignDatabase $event) use ($tenant) {
            return $event->tenant->getTenantKey() === $tenant->getTenantKey();
        });
    }

    // Event wasn't dispatched in the central app (No tenant present in the event)
    Event::assertNotDispatched(SyncedResourceSavedInForeignDatabase::class, function (SyncedResourceSavedInForeignDatabase $event) {
        return $event->tenant === null;
    });

    // Flush event log
    Event::fake([SyncedResourceSavedInForeignDatabase::class]);

    $t3->run(function () {
        TenantUser::first()->update(['name' => 'John 3']);
    });

    Event::assertDispatched(SyncedResourceSavedInForeignDatabase::class, function (SyncedResourceSavedInForeignDatabase $event) {
        return $event->tenant?->getTenantKey() === 't1';
    });
    Event::assertDispatched(SyncedResourceSavedInForeignDatabase::class, function (SyncedResourceSavedInForeignDatabase $event) {
        return $event->tenant?->getTenantKey() === 't2';
    });

    // Event wasn't dispatched for t3
    Event::assertNotDispatched(SyncedResourceSavedInForeignDatabase::class, function (SyncedResourceSavedInForeignDatabase $event) {
        return $event->tenant?->getTenantKey() === 't3';
    });

    // Event wasn't dispatched in the central app (No tenant present in the event)
    Event::assertDispatched(SyncedResourceSavedInForeignDatabase::class, function (SyncedResourceSavedInForeignDatabase $event) {
        return is_null($event->tenant);
    });

    // Flush
    Event::fake([SyncedResourceSavedInForeignDatabase::class]);

    $centralUser->update([
        'name' => 'John Central',
    ]);

    foreach ([$t1, $t2, $t3] as $tenant) {
        Event::assertDispatched(SyncedResourceSavedInForeignDatabase::class, function (SyncedResourceSavedInForeignDatabase $event) use ($tenant) {
            return $event->tenant->getTenantKey() === $tenant->getTenantKey();
        });
    }

    // Event wasn't dispatched in the central app
    Event::assertNotDispatched(SyncedResourceSavedInForeignDatabase::class, function (SyncedResourceSavedInForeignDatabase $event) {
        return is_null($event->tenant);
    });
});

test('resources are synced only when the shouldSync method returns true', function (bool $enabled) {
    TenantUser::$shouldSync = $enabled;
    CentralUser::$shouldSync = $enabled;

    [$tenant1, $tenant2] = createTenantsAndRunMigrations();
    migrateUsersTableForTenants();

    tenancy()->initialize($tenant1);

    TenantUser::create([
        'global_id' => 'absd',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'password',
        'role' => 'commenter',
    ]);

    tenancy()->end();

    expect(CentralUser::all())->toHaveCount($enabled ? 1 : 0);
    expect(CentralUser::whereGlobalId('absd')->exists())->toBe($enabled);

    $centralUser = CentralUser::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'password',
        'role' => 'commenter',
    ]);

    $centralUser->tenants()->attach($tenant2);

    $tenant2->run(function () use ($enabled) {
        expect(TenantUser::all())->toHaveCount($enabled ? 1 : 0);
        expect(TenantUser::whereGlobalId('acme')->exists())->toBe($enabled);
    });
})->with([
    true,
    false,
]);

test('deleting SyncMaster automatically deletes its Syncables', function (bool $morphPivot) {
    $centralUserModel = CentralUser::class;
    $tenantUserModel = TenantUser::class;

    if ($morphPivot) {
        config(['tenancy.models.tenant' => MorphTenant::class]);
        // Use base models if the tenant model uses a polymorphic pivot (which is the default in a real app)
        $centralUserModel = BaseCentralUser::class;
        $tenantUserModel = BaseTenantUser::class;
    }

    [$tenant] = createTenantsAndRunMigrations();

    $syncMaster = $centralUserModel::create([
        'global_id' => 'cascade_user',
        'name' => 'Central user',
        'email' => 'central@localhost',
        'password' => 'password',
        'role' => 'cascade_user',
    ]);

    $syncMaster->tenants()->attach($tenant);

    $syncMaster->delete();

    // Deleting SyncMaster deletes pivot records with the SyncMaster's global ID
    expect(DB::select("SELECT * FROM tenant_users WHERE tenant_id = ?", [$tenant->getTenantKey()]))->toHaveCount(0);

    tenancy()->initialize($tenant);

    expect($tenantUserModel::firstWhere('global_id', 'cascade_user'))->toBeNull(); // Delete has cascaded
})->with([
    'polymorphic pivot' => true,
    'basic pivot' => false,
]);

test('tenant pivot records are deleted along with the tenants to which they belong to', function() {
    [$tenant] = createTenantsAndRunMigrations();

    $syncMaster = CentralUser::create([
        'global_id' => 'cascade_user',
        'name' => 'Central user',
        'email' => 'central@localhost',
        'password' => 'password',
        'role' => 'cascade_user',
    ]);

    $syncMaster->tenants()->attach($tenant);

    $tenant->delete();

    // Deleting tenant deletes its pivot records
    expect(DB::select("SELECT * FROM tenant_users WHERE tenant_id = ?", [$tenant->getTenantKey()]))->toHaveCount(0);
});

test('trashed resources are synced correctly', function () {
    [$tenant1, $tenant2] = createTenantsAndRunMigrations();
    migrateUsersTableForTenants();
    addExtraColumns(true);

    // Include trashed resources in syncing queries
    UpdateOrCreateSyncedResource::$scopeGetModelQuery = function (Builder $query) {
        if ($query->hasMacro('withTrashed')) {
            $query->withTrashed();
        }
    };

    $centralUser = CentralUserWithSoftDeletes::create([
        'global_id' => 'user',
        'name' => 'Central user',
        'email' => 'central@localhost',
        'password' => 'password',
        'role' => 'commenter',
        'foo' => 'foo',
    ]);

    $centralUser->tenants()->attach($tenant1);
    $centralUser->tenants()->attach($tenant2);

    tenancy()->initialize($tenant1);

    // Synced resources aren't soft deleted from other tenants
    TenantUserWithSoftDeletes::first()->delete();

    tenancy()->initialize($tenant2);

    expect(TenantUserWithSoftDeletes::withTrashed()->first()->trashed())->toBeFalse();

    tenancy()->end();

    // Synced resources are soft deleted from tenants when the central resource gets deleted
    expect(CentralUserWithSoftDeletes::first())->delete();

    tenancy()->initialize($tenant2);

    expect(TenantUserWithSoftDeletes::withTrashed()->first()->trashed())->toBeTrue();

    // Update soft deleted synced resource
    TenantUserWithSoftDeletes::withTrashed()->first()->update(['name' => $newName = 'Updated name']);

    tenancy()->initialize($tenant1);

    $tenantResource = TenantUserWithSoftDeletes::withTrashed()->first();
    expect($tenantResource->name)->toBe($newName); // Value synced
    expect($tenantResource->trashed())->toBeTrue();

    tenancy()->end();

    $centralResource = CentralUserWithSoftDeletes::withTrashed()->first();
    expect($centralResource->name)->toBe($newName); // Value synced
    expect($centralResource->trashed())->toBeTrue();

    tenancy()->initialize($tenant2);

    $tenantResource = TenantUserWithSoftDeletes::withTrashed()->first();
    expect($tenantResource->name)->toBe($newName);
    // The trashed status is not synced even after updating another tenant resource
    expect($tenantResource->trashed())->toBeTrue();
});

test('restoring soft deleted resources works', function () {
    [$tenant1, $tenant2] = createTenantsAndRunMigrations();
    migrateUsersTableForTenants();
    addExtraColumns(true);

    CentralUserWithSoftDeletes::create([
        'global_id' => 'user',
        'name' => 'Central user',
        'email' => 'central@localhost',
        'password' => 'password',
        'role' => 'commenter',
        'foo' => 'foo',
    ]);

    tenancy()->initialize($tenant1);

    TenantUserWithSoftDeletes::create([
        'global_id' => 'user',
        'name' => 'Tenant user',
        'email' => 'tenant@localhost',
        'password' => 'password',
        'role' => 'commenter',
        'foo' => 'foo',
    ]);

    tenancy()->initialize($tenant2);

    TenantUserWithSoftDeletes::create([
        'global_id' => 'user',
        'name' => 'Tenant user',
        'email' => 'tenant@localhost',
        'password' => 'password',
        'role' => 'commenter',
        'foo' => 'foo',
    ]);

    tenancy()->end();

    // Synced resources are deleted from all tenants if the central resource gets deleted
    CentralUserWithSoftDeletes::first()->delete();

    expect(CentralUserWithSoftDeletes::withTrashed()->first()->trashed())->toBeTrue();

    tenancy()->runForMultiple([$tenant1, $tenant2], function () {
        expect(TenantUserWithSoftDeletes::withTrashed()->first()->trashed())->toBeTrue();
    });

    // Restoring a central resource restores tenant resources
    CentralUserWithSoftDeletes::withTrashed()->first()->restore();

    tenancy()->runForMultiple([$tenant1, $tenant2], function () {
        expect(TenantUserWithSoftDeletes::withTrashed()->first()->trashed())->toBeFalse();
    });
});

test('using forceDelete on a central resource with soft deletes force deletes the tenant resources', function () {
    [$tenant1, $tenant2] = createTenantsAndRunMigrations();
    migrateUsersTableForTenants();
    addExtraColumns(true);

    $centralUser = CentralUserWithSoftDeletes::create([
        'global_id' => 'user',
        'name' => 'Central user',
        'email' => 'central@localhost',
        'password' => 'password',
        'role' => 'commenter',
        'foo' => 'foo',
    ]);

    $centralUser->tenants()->attach($tenant1);
    $centralUser->tenants()->attach($tenant2);

    // Force deleting a tenant resource does not affect resources of other tenants
    tenancy()->initialize($tenant1);

    TenantUserWithSoftDeletes::firstWhere('global_id', 'user')->forceDelete();

    tenancy()->initialize($tenant2);

    expect(TenantUserWithSoftDeletes::firstWhere('global_id', 'user'))->not()->toBeNull();

    tenancy()->end();

    // Synced resources are deleted from all tenants if the central resource gets force deleted
    CentralUserWithSoftDeletes::firstWhere('global_id', 'user')->forceDelete();

    expect(CentralUserWithSoftDeletes::withTrashed()->firstWhere('global_id', 'user'))->toBeNull();

    tenancy()->initialize($tenant2);

    expect(TenantUserWithSoftDeletes::withTrashed()->firstWhere('global_id', 'user'))->toBeNull();
});

test('resource creation works correctly when tenant resource provides defaults in the creation attributes', function () {
    [$tenant1, $tenant2] = createTenantsAndRunMigrations();

    addExtraColumns();

    // Attribute names
    CentralUser::$creationAttributes = ['role'];

    // Mixed (attribute name + defaults)
    TenantUser::$creationAttributes = [
        'name' => 'Default Name',
        'email' => 'default@localhost',
        'password' => 'password',
        'role' => 'admin',
        'foo' => 'bar',
    ];

    $centralUser = CentralUser::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter',
        'foo' => 'bar', // foo does not exist in tenant resource
    ]);

    $tenant1->run(function () {
        expect(TenantUser::all())->toHaveCount(0);
    });

    // When central resource provides the attribute names in $creationAttributes
    // The tenant resource will be created using them
    $centralUser->tenants()->attach($tenant1);

    $tenant1->run(function () {
        $tenantUser = TenantUser::all();
        expect($tenantUser)->toHaveCount(1);
        expect($tenantUser->first()->global_id)->toBe('acme');
        expect($tenantUser->first()->email)->toBe('john@localhost');
        // 'foo' attribute is not provided by central model
        expect($tenantUser->first()->foo)->toBeNull();
    });

    tenancy()->initialize($tenant2);

    // Creating a tenant resource creates a central resource
    // Using resource's creation attributes
    TenantUser::create([
        'global_id' => 'asdf',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter',
    ]);

    tenancy()->end();

    // Central user was created using the defaults provided in the tenant resource
    // Creating TenantUser created a CentralUser with the same global_id
    $centralUser = CentralUser::whereGlobalId('asdf')->first();
    expect($centralUser)->not()->toBeNull();
    expect($centralUser->name)->toBe('Default Name');
    expect($centralUser->email)->toBe('default@localhost');
    expect($centralUser->password)->toBe('password');
    expect($centralUser->role)->toBe('admin');
    expect($centralUser->foo)->toBe('bar');
});

test('resource creation works correctly when central resource provides defaults in the creation attributes', function () {
    [$tenant1, $tenant2] = createTenantsAndRunMigrations();

    addExtraColumns();

    CentralUser::$creationAttributes = [
        'name' => 'Default User',
        'email' => 'default@localhost',
        'password' => 'password',
        'role' => 'admin',
    ];

    TenantUser::$creationAttributes = [
        // Central user requires 'foo', but tenant user does not have it
        // So we provide a default here in order for the central resource to be created when creating the tenant resource
        'foo' => 'bar',
        'role',
    ];

    $centralUser = CentralUser::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter',
        'foo' => 'bar',
    ]);

    $tenant1->run(function () {
        expect(TenantUser::all())->toHaveCount(0);
    });

    $centralUser->tenants()->attach($tenant1);

    $tenant1->run(function () {
        // Assert tenant resource was created using the defaults provided in the central resource
        $tenantUser = TenantUser::first();
        expect($tenantUser)->not()->toBeNull();
        expect($tenantUser->global_id)->toBe('acme');
        expect($tenantUser->email)->toBe('default@localhost');
        expect($tenantUser->password)->toBe('password');
        expect($tenantUser->role)->toBe('admin');
    });

    tenancy()->initialize($tenant2);

    // The creation attributes provided in the tenant resource will be used in the newly created central resource
    TenantUser::create([
        'global_id' => 'asdf',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter',
    ]);

    tenancy()->end();

    // Assert central resource was created using the provided attributes
    $centralUser = CentralUser::whereGlobalId('asdf')->first();

    expect($centralUser)->not()->toBeNull();
    expect($centralUser->email)->toBe('john@localhost');
    expect($centralUser->password)->toBe('secret');
    expect($centralUser->role)->toBe('commenter');
    expect($centralUser->foo)->toBe('bar');
});

/**
 * Create two tenants and run migrations for those tenants.
 *
 * @return Tenant[]
 */
function createTenantsAndRunMigrations(): array
{
    $tenantModel = tenancy()->model();

    [$tenant1, $tenant2] = [$tenantModel::create(), $tenantModel::create()];

    migrateUsersTableForTenants();

    return [$tenant1, $tenant2];
}

function addExtraColumns(bool $tenantDbs = false): void
{
    // Migrate extra column "foo" in central DB
    pest()->artisan('migrate', [
        '--path' => __DIR__ . '/Etc/synced_resource_migrations/users_extra',
        '--realpath' => true,
    ])->assertExitCode(0);

    if ($tenantDbs) {
        pest()->artisan('tenants:migrate', [
            '--path' => __DIR__ . '/Etc/synced_resource_migrations/users_extra',
            '--realpath' => true,
        ])->assertExitCode(0);
    }
}

function migrateUsersTableForTenants(): void
{
    pest()->artisan('tenants:migrate', [
        '--path' => __DIR__ . '/Etc/synced_resource_migrations/users',
        '--realpath' => true,
    ])->assertExitCode(0);
}

function migrateCompaniesTableForTenants(): void
{
    pest()->artisan('tenants:migrate', [
        '--path' => __DIR__ . '/Etc/synced_resource_migrations/companies',
        '--realpath' => true,
    ])->assertExitCode(0);
}

class CentralUser extends BaseCentralUser
{
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_users', 'global_user_id', 'tenant_id', 'global_id')
            ->using(TenantPivot::class);
    }
}

class TenantUser extends BaseTenantUser
{
    public function getCentralModelName(): string
    {
        return CentralUser::class;
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_users', 'global_user_id', 'tenant_id', 'global_id')
            ->using(TenantPivot::class);
    }
}

class TenantPivot extends BasePivot
{
    public $table = 'tenant_users';
}

class CentralUserWithSoftDeletes extends CentralUser
{
    use SoftDeletes;

    public function getTenantModelName(): string
    {
        return TenantUserWithSoftDeletes::class;
    }

    public function getCreationAttributes(): array
    {
        return array_merge($this->getSyncedAttributeNames(), [
            'role' => 'role',
            'foo' => 'foo', // extra column
        ]);
    }
}

class TenantUserWithSoftDeletes extends TenantUser
{
    use SoftDeletes;

    public function getCentralModelName(): string
    {
        return CentralUserWithSoftDeletes::class;
    }
}

class MorphTenant extends Tenant
{
    public function users(): MorphToMany
    {
        return $this->morphedByMany(BaseCentralUser::class, 'tenant_resources', 'tenant_resources', 'tenant_id', 'resource_global_id', 'id', 'global_id')
            ->using(TenantMorphPivot::class);
    }

    public function companies(): MorphToMany
    {
        return $this->morphedByMany(CentralCompany::class, 'tenant_resources', 'tenant_resources', 'tenant_id', 'resource_global_id', 'id', 'global_id')
            ->using(TenantMorphPivot::class);
    }
}

class CentralCompany extends Model implements SyncMaster
{
    use ResourceSyncing, CentralConnection;

    protected $guarded = [];

    public $timestamps = false;

    public $table = 'companies';

    public function getTenantModelName(): string
    {
        return TenantCompany::class;
    }

    public function getCentralModelName(): string
    {
        return static::class;
    }

    public function getSyncedAttributeNames(): array
    {
        return [
            'global_id',
            'name',
            'email',
        ];
    }
}
class TenantCompany extends Model implements Syncable
{
    use ResourceSyncing;

    protected $table = 'companies';

    protected $guarded = [];

    public $timestamps = false;

    public function getCentralModelName(): string
    {
        return CentralCompany::class;
    }

    public function getSyncedAttributeNames(): array
    {
        return [
            'global_id',
            'name',
            'email',
        ];
    }
}
