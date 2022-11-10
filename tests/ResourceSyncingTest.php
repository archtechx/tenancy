<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Contracts\Syncable;
use Stancl\Tenancy\Contracts\SyncMaster;
use Stancl\Tenancy\Database\Concerns\CentralConnection;
use Stancl\Tenancy\Database\Concerns\ResourceSyncing;
use Stancl\Tenancy\Database\Models\TenantPivot;
use Stancl\Tenancy\Database\DatabaseConfig;
use Stancl\Tenancy\Events\SyncedResourceChangedInForeignDatabase;
use Stancl\Tenancy\Events\SyncedResourceSaved;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Exceptions\ModelNotSyncMasterException;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Listeners\UpdateSyncedResource;
use Stancl\Tenancy\Tests\Etc\Tenant;

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

    UpdateSyncedResource::$shouldQueue = false; // Global state cleanup
    Event::listen(SyncedResourceSaved::class, UpdateSyncedResource::class);

    // Run migrations on central connection
    pest()->artisan('migrate', [
        '--path' => [
            __DIR__ . '/Etc/synced_resource_migrations',
            __DIR__ . '/Etc/synced_resource_migrations/users',
        ],
        '--realpath' => true,
    ])->assertExitCode(0);
});

test('an event is triggered when a synced resource is changed', function () {
    Event::fake([SyncedResourceSaved::class]);

    $user = ResourceUser::create([
        'name' => 'Foo',
        'email' => 'foo@email.com',
        'password' => 'secret',
        'global_id' => 'foo',
        'role' => 'foo',
    ]);

    Event::assertDispatched(SyncedResourceSaved::class, function (SyncedResourceSaved $event) use ($user) {
        return $event->model === $user;
    });
});

test('only the synced columns are updated in the central db', function () {
    // Create user in central DB
    $user = CentralUser::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'superadmin', // unsynced
    ]);

    $tenant = ResourceTenant::create();
    migrateUsersTableForTenants();

    tenancy()->initialize($tenant);

    // Create the same user in tenant DB
    $user = ResourceUser::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter', // unsynced
    ]);

    // Update user in tenant DB
    $user->update([
        'name' => 'John Foo', // synced
        'email' => 'john@foreignhost', // synced
        'role' => 'admin', // unsynced
    ]);

    // Assert new values
    pest()->assertEquals([
        'id' => 1,
        'global_id' => 'acme',
        'name' => 'John Foo',
        'email' => 'john@foreignhost',
        'password' => 'secret',
        'role' => 'admin',
    ], $user->getAttributes());

    tenancy()->end();

    // Assert changes bubbled up
    pest()->assertEquals([
        'id' => 1,
        'global_id' => 'acme',
        'name' => 'John Foo', // synced
        'email' => 'john@foreignhost', // synced
        'password' => 'secret', // no changes
        'role' => 'superadmin', // unsynced
    ], ResourceUser::first()->getAttributes());
});

// This tests attribute list on the central side, and default values on the tenant side
// Those two don't depend on each other, we're just testing having each option on each side
// using tests that combine the two, to avoid having an excessively long and complex test suite
test('sync resource creation works when central model provides attributes and tenant model provides default values', function () {
    [$tenant1, $tenant2] = createTenantsAndRunMigrations();

    addExtraColumnToCentralDB();

    $centralUser = CentralUserProvidingAttributeNames::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter',
        'foo' => 'bar', // foo does not exist in resource model
    ]);

    $tenant1->run(function () {
        expect(TenantUserProvidingDefaultValues::all())->toHaveCount(0);
    });

    // When central model provides the list of attributes, resource model will be created from the provided list of attributes' values
    $centralUser->tenants()->attach('t1');

    $tenant1->run(function () {
        $resourceUser = TenantUserProvidingDefaultValues::all();
        expect($resourceUser)->toHaveCount(1);
        expect($resourceUser->first()->global_id)->toBe('acme');
        expect($resourceUser->first()->email)->toBe('john@localhost');
        // 'foo' attribute is not provided by central model
        expect($resourceUser->first()->foo)->toBeNull();
    });

    tenancy()->initialize($tenant2);

    // When resource model provides the list of default values, central model will be created from the provided list of default values
    TenantUserProvidingDefaultValues::create([
        'global_id' => 'asdf',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter',
    ]);

    tenancy()->end();

    // Assert central user was created using the list of default values
    $centralUser = CentralUserProvidingAttributeNames::whereGlobalId('asdf')->first();
    expect($centralUser)->not()->toBeNull();
    expect($centralUser->name)->toBe('Default Name');
    expect($centralUser->email)->toBe('default@localhost');
    expect($centralUser->password)->toBe('password');
    expect($centralUser->role)->toBe('admin');
    expect($centralUser->foo)->toBe('bar');
});

// This tests default values on the central side, and attribute list on the tenant side
// Those two don't depend on each other, we're just testing having each option on each side
// using tests that combine the two, to avoid having an excessively long and complex test suite
test('sync resource creation works when central model provides default values and tenant model provides attributes', function () {
    [$tenant1, $tenant2] = createTenantsAndRunMigrations();

    addExtraColumnToCentralDB();

    $centralUser = CentralUserProvidingDefaultValues::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter',
        'foo' => 'bar', // foo does not exist in resource model
    ]);

    $tenant1->run(function () {
        expect(TenantUserProvidingDefaultValues::all())->toHaveCount(0);
    });

    // When central model provides the list of default values, resource model will be created from the provided list of default values
    $centralUser->tenants()->attach('t1');

    $tenant1->run(function () {
        // Assert resource user was created using the list of default values
        $resourceUser = TenantUserProvidingDefaultValues::first();
        expect($resourceUser)->not()->toBeNull();
        expect($resourceUser->global_id)->toBe('acme');
        expect($resourceUser->email)->toBe('default@localhost');
        expect($resourceUser->password)->toBe('password');
        expect($resourceUser->role)->toBe('admin');
    });

    tenancy()->initialize($tenant2);

    // When resource model provides the list of attributes, central model will be created from the provided list of attributes' values
    TenantUserProvidingAttributeNames::create([
        'global_id' => 'asdf',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter',
    ]);

    tenancy()->end();

    // Assert central user was created using the list of provided attributes
    $centralUser = CentralUserProvidingAttributeNames::whereGlobalId('asdf')->first();
    expect($centralUser)->not()->toBeNull();
    expect($centralUser->email)->toBe('john@localhost');
    expect($centralUser->password)->toBe('secret');
    expect($centralUser->role)->toBe('commenter');
});

// This tests mixed attribute list/defaults on the central side, and no specified attributes on the tenant side
// Those two don't depend on each other, we're just testing having each option on each side
// using tests that combine the two, to avoid having an excessively long and complex test suite
test('sync resource creation works when central model provides mixture and tenant model provides nothing', function () {
    [$tenant1, $tenant2] = createTenantsAndRunMigrations();

    $centralUser = CentralUserProvidingMixture::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'password',
        'role' => 'commentator'
    ]);

    $tenant1->run(function () {
        expect(ResourceUser::all())->toHaveCount(0);
    });

    // When central model provides the list of a mixture (attributes and default values), resource model will be created from the provided list of mixture (attributes and default values)
    $centralUser->tenants()->attach('t1');

    $tenant1->run(function () {
        $resourceUser = ResourceUser::first();

        // Assert resource user was created using the provided attributes and default values
        expect($resourceUser->global_id)->toBe('acme');
        expect($resourceUser->name)->toBe('John Doe');
        expect($resourceUser->email)->toBe('john@localhost');
        // default values
        expect($resourceUser->role)->toBe('admin');
        expect($resourceUser->password)->toBe('secret');
    });

    tenancy()->initialize($tenant2);

    // When resource model provides nothing/null, the central model will be created as a 1:1 copy of resource model
    $resourceUser = ResourceUser::create([
        'global_id' => 'acmey',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'password',
        'role' => 'commentator'
    ]);

    tenancy()->end();

    $centralUser = CentralUserProvidingMixture::whereGlobalId('acmey')->first();
    expect($resourceUser->getSyncedCreationAttributes())->toBeNull();

    $centralUser = $centralUser->toArray();
    $resourceUser = $resourceUser->toArray();
    unset($centralUser['id']);
    unset($resourceUser['id']);

    // Assert central user created as 1:1 copy of resource model except "id"
    expect($centralUser)->toBe($resourceUser);
});

// This tests no specified attributes on the central side, and mixed attribute list/defaults on the tenant side
// Those two don't depend on each other, we're just testing having each option on each side
// using tests that combine the two, to avoid having an excessively long and complex test suite
test('sync resource creation works when central model provides nothing and tenant model provides mixture', function () {
    [$tenant1, $tenant2] = createTenantsAndRunMigrations();

    $centralUser = CentralUser::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'password',
        'role' => 'commenter',
    ]);

    $tenant1->run(function () {
        expect(TenantUserProvidingMixture::all())->toHaveCount(0);
    });

    // When central model provides nothing/null, the resource model will be created as a 1:1 copy of central model
    $centralUser->tenants()->attach('t1');

    expect($centralUser->getSyncedCreationAttributes())->toBeNull();
    $tenant1->run(function () use ($centralUser) {
        $resourceUser = TenantUserProvidingMixture::first();
        expect($resourceUser)->not()->toBeNull();
        $resourceUser = $resourceUser->toArray();
        $centralUser = $centralUser->withoutRelations()->toArray();
        unset($resourceUser['id']);
        unset($centralUser['id']);

        expect($resourceUser)->toBe($centralUser);
    });

    tenancy()->initialize($tenant2);

    // When resource model provides the list of a mixture (attributes and default values), central model will be created from the provided list of mixture (attributes and default values)
    TenantUserProvidingMixture::create([
        'global_id' => 'absd',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'password',
        'role' => 'commenter',
    ]);

    tenancy()->end();

    $centralUser = CentralUser::whereGlobalId('absd')->first();

    // Assert central user was created using the provided list of attributes and default values
    expect($centralUser->name)->toBe('John Doe');
    expect($centralUser->email)->toBe('john@localhost');
    // default values
    expect($centralUser->role)->toBe('admin');
    expect($centralUser->password)->toBe('secret');
});

test('creating the resource in tenant database creates it in central database and creates the mapping', function () {
    creatingResourceInTenantDatabaseCreatesAndMapInCentralDatabase();
});

test('trying to update synced resources from central context using tenant models results in an exception', function () {
    creatingResourceInTenantDatabaseCreatesAndMapInCentralDatabase();

    tenancy()->end();
    expect(tenancy()->initialized)->toBeFalse();

    pest()->expectException(ModelNotSyncMasterException::class);
    ResourceUser::first()->update(['role' => 'foobar']);
});

test('attaching a tenant to the central resource triggers a pull from the tenant db', function () {
    $centralUser = CentralUser::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter', // unsynced
    ]);

    $tenant = ResourceTenant::create([
        'id' => 't1',
    ]);
    migrateUsersTableForTenants();

    $tenant->run(function () {
        expect(ResourceUser::all())->toHaveCount(0);
    });

    $centralUser->tenants()->attach('t1');

    $tenant->run(function () {
        expect(ResourceUser::all())->toHaveCount(1);
    });
});

test('attaching users to tenants does not do anything', function () {
    $centralUser = CentralUser::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter', // unsynced
    ]);

    $tenant = ResourceTenant::create([
        'id' => 't1',
    ]);
    migrateUsersTableForTenants();

    $tenant->run(function () {
        expect(ResourceUser::all())->toHaveCount(0);
    });

    // The child model is inaccessible in the Pivot Model, so we can't fire any events.
    $tenant->users()->attach($centralUser);

    $tenant->run(function () {
        // Still zero
        expect(ResourceUser::all())->toHaveCount(0);
    });
});

test('resources are synced only to workspaces that have the resource', function () {
    $centralUser = CentralUser::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter', // unsynced
    ]);

    $t1 = ResourceTenant::create([
        'id' => 't1',
    ]);

    $t2 = ResourceTenant::create([
        'id' => 't2',
    ]);

    $t3 = ResourceTenant::create([
        'id' => 't3',
    ]);
    migrateUsersTableForTenants();

    $centralUser->tenants()->attach('t1');
    $centralUser->tenants()->attach('t2');
    // t3 is not attached

    $t1->run(function () {
        // assert user exists
        expect(ResourceUser::all())->toHaveCount(1);
    });

    $t2->run(function () {
        // assert user exists
        expect(ResourceUser::all())->toHaveCount(1);
    });

    $t3->run(function () {
        // assert user does NOT exist
        expect(ResourceUser::all())->toHaveCount(0);
    });
});

test('when a resource exists in other tenant dbs but is created in a tenant db the synced columns are updated in the other dbs', function () {
    // create shared resource
    $centralUser = CentralUser::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter', // unsynced
    ]);

    $t1 = ResourceTenant::create([
        'id' => 't1',
    ]);
    $t2 = ResourceTenant::create([
        'id' => 't2',
    ]);
    migrateUsersTableForTenants();

    // Copy (cascade) user to t1 DB
    $centralUser->tenants()->attach('t1');

    $t2->run(function () {
        // Create user with the same global ID in t2 database
        ResourceUser::create([
            'global_id' => 'acme',
            'name' => 'John Foo', // changed
            'email' => 'john@foo', // changed
            'password' => 'secret',
            'role' => 'superadmin', // unsynced
        ]);
    });

    $centralUser = CentralUser::first();
    expect($centralUser->name)->toBe('John Foo'); // name changed
    expect($centralUser->email)->toBe('john@foo'); // email changed
    expect($centralUser->role)->toBe('commenter'); // role didn't change

    $t1->run(function () {
        $user = ResourceUser::first();
        expect($user->name)->toBe('John Foo'); // name changed
        expect($user->email)->toBe('john@foo'); // email changed
        expect($user->role)->toBe('commenter'); // role didn't change, i.e. is the same as from the original copy from central
    });
});

test('the synced columns are updated in other tenant dbs where the resource exists', function () {
    // create shared resource
    $centralUser = CentralUser::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter', // unsynced
    ]);

    $t1 = ResourceTenant::create([
        'id' => 't1',
    ]);
    $t2 = ResourceTenant::create([
        'id' => 't2',
    ]);
    $t3 = ResourceTenant::create([
        'id' => 't3',
    ]);
    migrateUsersTableForTenants();

    // Copy (cascade) user to t1 DB
    $centralUser->tenants()->attach('t1');
    $centralUser->tenants()->attach('t2');
    $centralUser->tenants()->attach('t3');

    $t3->run(function () {
        ResourceUser::first()->update([
            'name' => 'John 3',
            'role' => 'employee', // unsynced
        ]);

        expect(ResourceUser::first()->role)->toBe('employee');
    });

    // Check that change was cascaded to other tenants
    $t1->run($check = function () {
        $user = ResourceUser::first();

        expect($user->name)->toBe('John 3'); // synced
        expect($user->role)->toBe('commenter'); // unsynced
    });
    $t2->run($check);

    // Check that change bubbled up to central DB
    expect(CentralUser::count())->toBe(1);
    $centralUser = CentralUser::first();
    expect($centralUser->name)->toBe('John 3'); // synced
    expect($centralUser->role)->toBe('commenter'); // unsynced
});

test('global id is generated using id generator when its not supplied', function () {
    $user = CentralUser::create([
        'name' => 'John Doe',
        'email' => 'john@doe',
        'password' => 'secret',
        'role' => 'employee',
    ]);

    pest()->assertNotNull($user->global_id);
});

test('when the resource doesnt exist in the tenant db non synced columns will cascade too', function () {
    $centralUser = CentralUser::create([
        'name' => 'John Doe',
        'email' => 'john@doe',
        'password' => 'secret',
        'role' => 'employee',
    ]);

    $t1 = ResourceTenant::create([
        'id' => 't1',
    ]);

    migrateUsersTableForTenants();

    $centralUser->tenants()->attach('t1');

    $t1->run(function () {
        expect(ResourceUser::first()->role)->toBe('employee');
    });
});

test('when the resource doesnt exist in the central db non synced columns will bubble up too', function () {
    $t1 = ResourceTenant::create([
        'id' => 't1',
    ]);

    migrateUsersTableForTenants();

    $t1->run(function () {
        ResourceUser::create([
            'name' => 'John Doe',
            'email' => 'john@doe',
            'password' => 'secret',
            'role' => 'employee',
        ]);
    });

    expect(CentralUser::first()->role)->toBe('employee');
});

test('the listener can be queued', function () {
    Queue::fake();
    UpdateSyncedResource::$shouldQueue = true;

    $t1 = ResourceTenant::create([
        'id' => 't1',
    ]);

    migrateUsersTableForTenants();

    Queue::assertNothingPushed();

    $t1->run(function () {
        ResourceUser::create([
            'name' => 'John Doe',
            'email' => 'john@doe',
            'password' => 'secret',
            'role' => 'employee',
        ]);
    });

    Queue::assertPushed(CallQueuedListener::class, function (CallQueuedListener $job) {
        return $job->class === UpdateSyncedResource::class;
    });
});

test('an event is fired for all touched resources', function () {
    Event::fake([SyncedResourceChangedInForeignDatabase::class]);

    // create shared resource
    $centralUser = CentralUser::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter', // unsynced
    ]);

    $t1 = ResourceTenant::create([
        'id' => 't1',
    ]);
    $t2 = ResourceTenant::create([
        'id' => 't2',
    ]);
    $t3 = ResourceTenant::create([
        'id' => 't3',
    ]);
    migrateUsersTableForTenants();

    // Copy (cascade) user to t1 DB
    $centralUser->tenants()->attach('t1');
    Event::assertDispatched(SyncedResourceChangedInForeignDatabase::class, function (SyncedResourceChangedInForeignDatabase $event) {
        return $event->tenant->getTenantKey() === 't1';
    });

    $centralUser->tenants()->attach('t2');
    Event::assertDispatched(SyncedResourceChangedInForeignDatabase::class, function (SyncedResourceChangedInForeignDatabase $event) {
        return $event->tenant->getTenantKey() === 't2';
    });

    $centralUser->tenants()->attach('t3');
    Event::assertDispatched(SyncedResourceChangedInForeignDatabase::class, function (SyncedResourceChangedInForeignDatabase $event) {
        return $event->tenant->getTenantKey() === 't3';
    });

    // Assert no event for central
    Event::assertNotDispatched(SyncedResourceChangedInForeignDatabase::class, function (SyncedResourceChangedInForeignDatabase $event) {
        return $event->tenant === null;
    });

    // Flush
    Event::fake([SyncedResourceChangedInForeignDatabase::class]);

    $t3->run(function () {
        ResourceUser::first()->update([
            'name' => 'John 3',
            'role' => 'employee', // unsynced
        ]);

        expect(ResourceUser::first()->role)->toBe('employee');
    });

    Event::assertDispatched(SyncedResourceChangedInForeignDatabase::class, function (SyncedResourceChangedInForeignDatabase $event) {
        return optional($event->tenant)->getTenantKey() === 't1';
    });
    Event::assertDispatched(SyncedResourceChangedInForeignDatabase::class, function (SyncedResourceChangedInForeignDatabase $event) {
        return optional($event->tenant)->getTenantKey() === 't2';
    });

    // Assert NOT dispatched in t3
    Event::assertNotDispatched(SyncedResourceChangedInForeignDatabase::class, function (SyncedResourceChangedInForeignDatabase $event) {
        return optional($event->tenant)->getTenantKey() === 't3';
    });

    // Assert dispatched in central
    Event::assertDispatched(SyncedResourceChangedInForeignDatabase::class, function (SyncedResourceChangedInForeignDatabase $event) {
        return $event->tenant === null;
    });

    // Flush
    Event::fake([SyncedResourceChangedInForeignDatabase::class]);

    $centralUser->update([
        'name' => 'John Central',
    ]);

    Event::assertDispatched(SyncedResourceChangedInForeignDatabase::class, function (SyncedResourceChangedInForeignDatabase $event) {
        return optional($event->tenant)->getTenantKey() === 't1';
    });
    Event::assertDispatched(SyncedResourceChangedInForeignDatabase::class, function (SyncedResourceChangedInForeignDatabase $event) {
        return optional($event->tenant)->getTenantKey() === 't2';
    });
    Event::assertDispatched(SyncedResourceChangedInForeignDatabase::class, function (SyncedResourceChangedInForeignDatabase $event) {
        return optional($event->tenant)->getTenantKey() === 't3';
    });
    // Assert NOT dispatched in central
    Event::assertNotDispatched(SyncedResourceChangedInForeignDatabase::class, function (SyncedResourceChangedInForeignDatabase $event) {
        return $event->tenant === null;
    });
});

// todo@tests
function creatingResourceInTenantDatabaseCreatesAndMapInCentralDatabase()
{
    // Assert no user in central DB
    expect(ResourceUser::all())->toHaveCount(0);

    $tenant = ResourceTenant::create();
    migrateUsersTableForTenants();

    tenancy()->initialize($tenant);

    // Create the same user in tenant DB
    ResourceUser::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter', // unsynced
    ]);

    tenancy()->end();

    // Assert user was created
    expect(CentralUser::first()->global_id)->toBe('acme');
    expect(CentralUser::first()->role)->toBe('commenter');

    // Assert mapping was created
    expect(CentralUser::first()->tenants)->toHaveCount(1);

    // Assert role change doesn't cascade
    CentralUser::first()->update(['role' => 'central superadmin']);
    tenancy()->initialize($tenant);
    expect(ResourceUser::first()->role)->toBe('commenter');
}

test('resources are synced only when sync is enabled', function (bool $enabled) {
    app()->instance('_tenancy_test_shouldSync', $enabled);

    [$tenant1, $tenant2] = createTenantsAndRunMigrations();
    migrateUsersTableForTenants();

    tenancy()->initialize($tenant1);

    TenantUserWithConditionalSync::create([
        'global_id' => 'absd',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'password',
        'role' => 'commenter',
    ]);

    tenancy()->end();

    expect(CentralUserWithConditionalSync::all())->toHaveCount($enabled ? 1 : 0);
    expect(CentralUserWithConditionalSync::whereGlobalId('absd')->exists())->toBe($enabled);

    $centralUser = CentralUserWithConditionalSync::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'password',
        'role' => 'commenter',
    ]);

    $centralUser->tenants()->attach('t2');

    $tenant2->run(function () use ($enabled) {
        expect(TenantUserWithConditionalSync::all())->toHaveCount($enabled ? 1 : 0);
        expect(TenantUserWithConditionalSync::whereGlobalId('acme')->exists())->toBe($enabled);
    });
})->with([[true], [false]]);

/**
 * Create two tenants and run migrations for those tenants.
 */
function createTenantsAndRunMigrations(): array
{
    [$tenant1, $tenant2] = [ResourceTenant::create(['id' => 't1']), ResourceTenant::create(['id' => 't2'])];

    migrateUsersTableForTenants();

    return [$tenant1, $tenant2];
}

function addExtraColumnToCentralDB(): void
{
    // migrate extra column "foo" in central DB
    pest()->artisan('migrate', [
        '--path' => __DIR__ . '/Etc/synced_resource_migrations/users_extra',
        '--realpath' => true,
    ])->assertExitCode(0);
}

function migrateUsersTableForTenants(): void
{
    pest()->artisan('tenants:migrate', [
        '--path' => __DIR__ . '/Etc/synced_resource_migrations/users',
        '--realpath' => true,
    ])->assertExitCode(0);
}

// Tenant model used for resource syncing setup
class ResourceTenant extends Tenant
{
    public function users()
    {
        return $this->belongsToMany(CentralUser::class, 'tenant_users', 'tenant_id', 'global_user_id', 'id', 'global_id')
            ->using(TenantPivot::class);
    }
}

class CentralUser extends Model implements SyncMaster
{
    use ResourceSyncing, CentralConnection;

    protected $guarded = [];

    public $timestamps = false;

    public $table = 'users';

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(ResourceTenant::class, 'tenant_users', 'global_user_id', 'tenant_id', 'global_id')
            ->using(TenantPivot::class);
    }

    public function getTenantModelName(): string
    {
        return ResourceUser::class;
    }

    public function getGlobalIdentifierKey(): string|int
    {
        return $this->getAttribute($this->getGlobalIdentifierKeyName());
    }

    public function getGlobalIdentifierKeyName(): string
    {
        return 'global_id';
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
            'password',
            'email',
        ];
    }
}

// Tenant users
class ResourceUser extends Model implements Syncable
{
    use ResourceSyncing;

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;

    public function getGlobalIdentifierKey(): string|int
    {
        return $this->getAttribute($this->getGlobalIdentifierKeyName());
    }

    public function getGlobalIdentifierKeyName(): string
    {
        return 'global_id';
    }

    public function getCentralModelName(): string
    {
        return CentralUser::class;
    }

    public function getSyncedAttributeNames(): array
    {
        return [
            'global_id',
            'name',
            'password',
            'email',
        ];
    }
}

// override method in ResourceUser class to return default attribute values
class TenantUserProvidingDefaultValues extends ResourceUser
{
    public function getSyncedCreationAttributes(): array
    {
        // Default values when creating resources from tenant to central DB
        return
            [
                'name' => 'Default Name',
                'email' => 'default@localhost',
                'password' => 'password',
                'role' => 'admin',
                'foo' => 'bar'
            ];
    }
}

// override method in ResourceUser class to return attribute names
class TenantUserProvidingAttributeNames extends ResourceUser
{
    public function getSyncedCreationAttributes(): array
    {
        // Attributes used when creating resources from tenant to central DB
        // Notice here we are not adding "code" filed because it doesn't
        // exist in central model
        return
            [
                'name',
                'password',
                'email',
                'role',
                'foo' => 'bar'
            ];
    }

}

// override method in CentralUser class to return attribute default values
class CentralUserProvidingDefaultValues extends CentralUser
{
    public function getSyncedCreationAttributes(): array
    {
        // Attributes default values when creating resources from central to tenant model
        return
            [
                'name' => 'Default User',
                'email' => 'default@localhost',
                'password' => 'password',
                'role' => 'admin',
            ];
    }
}

// override method in CentralUser class to return attribute names
class CentralUserProvidingAttributeNames extends CentralUser
{
    public function getSyncedCreationAttributes(): array
    {
        // Attributes used when creating resources from central to tenant DB
        return
            [
                'global_id',
                'name',
                'password',
                'email',
                'role',
            ];
    }
}

class CentralUserProvidingMixture extends CentralUser
{
    public function getSyncedCreationAttributes(): array
    {
        return [
            'name',
            'email',
            'role' => 'admin',
            'password' => 'secret',
        ];
    }
}

class TenantUserProvidingMixture extends ResourceUser
{
    public function getSyncedCreationAttributes(): array
    {
        return [
            'name',
            'email',
            'role' => 'admin',
            'password' => 'secret',
        ];
    }
}

class CentralUserWithConditionalSync extends CentralUser
{
    public function shouldSync(): bool
    {
        return app('_tenancy_test_shouldSync');
    }
}

class TenantUserWithConditionalSync extends ResourceUser
{
    public function shouldSync(): bool
    {
        return app('_tenancy_test_shouldSync');
    }
}
