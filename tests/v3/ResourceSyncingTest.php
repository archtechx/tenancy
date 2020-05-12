<?php

namespace Stancl\Tenancy\Tests\v3;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Stancl\Tenancy\Contracts\Syncable;
use Stancl\Tenancy\Contracts\SyncMaster;
use Stancl\Tenancy\Database\Models\Concerns\CentralConnection;
use Stancl\Tenancy\Database\Models\Concerns\ResourceSyncing;
use Stancl\Tenancy\Database\Models;
use Stancl\Tenancy\Database\Models\TenantPivot;
use Stancl\Tenancy\Events\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Events\Listeners\JobPipeline;
use Stancl\Tenancy\Events\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Events\Listeners\UpdateSyncedResource;
use Stancl\Tenancy\Events\SyncedResourceChangedInForeignDatabase;
use Stancl\Tenancy\Events\SyncedResourceSaved;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Exceptions\ModelNotSyncMaster;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\TenancyBootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Tests\TestCase;

class ResourceSyncingTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        config(['tenancy.bootstrappers' => [
            DatabaseTenancyBootstrapper::class,
        ]]);

        Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
            return $event->tenant;
        })->toListener());

        Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
        Event::listen(TenancyEnded::class, RevertToCentralContext::class);

        UpdateSyncedResource::$shouldQueue = false; // global state cleanup
        Event::listen(SyncedResourceSaved::class, UpdateSyncedResource::class);

        $this->artisan('migrate', [
            '--path' => [
                __DIR__ . '/../Etc/synced_resource_migrations',
                __DIR__ . '/../Etc/synced_resource_migrations/users'
            ],
            '--realpath' => true,
        ])->assertExitCode(0);
    }

    protected function migrateTenants()
    {
        $this->artisan('tenants:migrate', [
            '--path' => __DIR__ . '/../Etc/synced_resource_migrations/users',
            '--realpath' => true,
        ])->assertExitCode(0);
    }

    /** @test */
    public function an_event_is_triggered_when_a_synced_resource_is_changed()
    {
        Event::fake([SyncedResourceSaved::class]);

        $user = User::create([
            'name' => 'Foo',
            'email' => 'foo@email.com',
            'password' => 'secret',
            'global_id' => 'foo',
            'role' => 'foo',
        ]);

        Event::assertDispatched(SyncedResourceSaved::class, function (SyncedResourceSaved $event) use ($user) {
            return $event->model === $user;
        });
    }

    /** @test */
    public function only_the_synced_columns_are_updated_in_the_central_db()
    {
        // Create user in central DB
        $user = CentralUser::create([
            'global_id' => 'acme',
            'name' => 'John Doe',
            'email' => 'john@localhost',
            'password' => 'secret',
            'role' => 'superadmin', // unsynced
        ]);

        $tenant = Tenant::create();
        $this->migrateTenants();

        tenancy()->initialize($tenant);

        // Create the same user in tenant DB
        $user = User::create([
            'global_id' => 'acme',
            'name' => 'John Doe',
            'email' => 'john@localhost',
            'password' => 'secret',
            'role' => 'commenter', // unsynced
        ]);

        // Update user in tenant DB
        $user->update([
            'role' => 'admin', // unsynced
            'name' => 'John Foo', // synceed
            'email' => 'john@foreignhost', // synceed
        ]);

        // Assert new values
        $this->assertEquals([
            'id' => 1,
            'global_id' => 'acme',
            'name' => 'John Foo',
            'email' => 'john@foreignhost',
            'password' => 'secret',
            'role' => 'admin',
        ], $user->getAttributes());

        tenancy()->end();

        // Assert changes bubbled up
        $this->assertEquals([
            'id' => 1,
            'global_id' => 'acme',
            'name' => 'John Foo', // synced
            'email' => 'john@foreignhost', // synced
            'password' => 'secret', // no changes
            'role' => 'superadmin', // unsynced
        ], User::first()->getAttributes());
    }

    /** @test */
    public function creating_the_resource_in_tenant_database_creates_it_in_central_database_and_creates_the_mapping()
    {
        // Assert no user in central DB
        $this->assertCount(0, User::all());

        $tenant = Tenant::create();
        $this->migrateTenants();

        tenancy()->initialize($tenant);

        // Create the same user in tenant DB
        User::create([
            'global_id' => 'acme',
            'name' => 'John Doe',
            'email' => 'john@localhost',
            'password' => 'secret',
            'role' => 'commenter', // unsynced
        ]);

        tenancy()->end();

        // Asset user was created
        $this->assertSame('acme', CentralUser::first()->global_id);
        $this->assertSame('commenter', CentralUser::first()->role);

        // Assert mapping was created
        $this->assertCount(1, CentralUser::first()->tenants);

        // Assert role change doesn't cascade
        CentralUser::first()->update(['role' => 'central superadmin']);
        tenancy()->initialize($tenant);
        $this->assertSame('commenter', User::first()->role);
    }

    /** @test */
    public function trying_to_update_synced_resources_from_central_context_using_tenant_models_results_in_an_exception()
    {
        $this->creating_the_resource_in_tenant_database_creates_it_in_central_database_and_creates_the_mapping();

        tenancy()->end();
        $this->assertFalse(tenancy()->initialized);

        $this->expectException(ModelNotSyncMaster::class);
        User::first()->update(['role' => 'foobar']);
    }

    /** @test */
    public function attaching_a_tenant_to_the_central_resource_triggers_a_pull_from_the_tenant_db()
    {
        $centralUser = CentralUser::create([
            'global_id' => 'acme',
            'name' => 'John Doe',
            'email' => 'john@localhost',
            'password' => 'secret',
            'role' => 'commenter', // unsynced
        ]);

        $tenant = Tenant::create([
            'id' => 't1',
        ]);
        $this->migrateTenants();

        $tenant->run(function () {
            $this->assertCount(0, User::all());
        });

        $centralUser->tenants()->attach('t1');

        $tenant->run(function () {
            $this->assertCount(1, User::all());
        });
    }

    /** @test */
    public function attaching_users_to_tenants_DOES_NOT_DO_ANYTHING()
    {
        $centralUser = CentralUser::create([
            'global_id' => 'acme',
            'name' => 'John Doe',
            'email' => 'john@localhost',
            'password' => 'secret',
            'role' => 'commenter', // unsynced
        ]);

        $tenant = Tenant::create([
            'id' => 't1',
        ]);
        $this->migrateTenants();

        $tenant->run(function () {
            $this->assertCount(0, User::all());
        });

        // The child model is inaccessible in the Pivot Model, so we can't fire any events.
        $tenant->users()->attach($centralUser);

        $tenant->run(function () {
            // Still zero
            $this->assertCount(0, User::all());
        });
    }

    /** @test */
    public function resources_are_synced_only_to_workspaces_that_have_the_resource()
    {
        $centralUser = CentralUser::create([
            'global_id' => 'acme',
            'name' => 'John Doe',
            'email' => 'john@localhost',
            'password' => 'secret',
            'role' => 'commenter', // unsynced
        ]);

        $t1 = Tenant::create([
            'id' => 't1',
        ]);

        $t2 = Tenant::create([
            'id' => 't2',
        ]);

        $t3 = Tenant::create([
            'id' => 't3',
        ]);
        $this->migrateTenants();

        $centralUser->tenants()->attach('t1');
        $centralUser->tenants()->attach('t2');
        // t3 is not attached

        $t1->run(function () {
            // assert user exists
            $this->assertCount(1, User::all());
        });

        $t2->run(function () {
            // assert user exists
            $this->assertCount(1, User::all());
        });

        $t3->run(function () {
            // assert user does NOT exist
            $this->assertCount(0, User::all());
        });
    }

    /** @test */
    public function when_a_resource_exists_in_other_tenant_dbs_but_is_CREATED_in_a_tenant_db_the_synced_columns_are_updated_in_the_other_dbs()
    {
        // create shared resource
        $centralUser = CentralUser::create([
            'global_id' => 'acme',
            'name' => 'John Doe',
            'email' => 'john@localhost',
            'password' => 'secret',
            'role' => 'commenter', // unsynced
        ]);

        $t1 = Tenant::create([
            'id' => 't1',
        ]);
        $t2 = Tenant::create([
            'id' => 't2',
        ]);
        $this->migrateTenants();

        // Copy (cascade) user to t1 DB
        $centralUser->tenants()->attach('t1');

        $t2->run(function () {
            // Create user with the same global ID in t2 database
            User::create([
                'global_id' => 'acme',
                'name' => 'John Foo', // changed
                'email' => 'john@foo', // changed
                'password' => 'secret',
                'role' => 'superadmin', // unsynced
            ]);
        });

        $centralUser = CentralUser::first();
        $this->assertSame('John Foo', $centralUser->name); // name changed
        $this->assertSame('john@foo', $centralUser->email); // email changed
        $this->assertSame('commenter', $centralUser->role); // role didn't change

        $t1->run(function () {
            $user = User::first();
            $this->assertSame('John Foo', $user->name); // name changed
            $this->assertSame('john@foo', $user->email); // email changed
            $this->assertSame('commenter', $user->role); // role didn't change, i.e. is the same as from the original copy from central
        });
    }

    /** @test */
    public function the_synced_columns_are_updated_in_other_tenant_dbs_where_the_resource_exists()
    {
        // create shared resource
        $centralUser = CentralUser::create([
            'global_id' => 'acme',
            'name' => 'John Doe',
            'email' => 'john@localhost',
            'password' => 'secret',
            'role' => 'commenter', // unsynced
        ]);

        $t1 = Tenant::create([
            'id' => 't1',
        ]);
        $t2 = Tenant::create([
            'id' => 't2',
        ]);
        $t3 = Tenant::create([
            'id' => 't3',
        ]);
        $this->migrateTenants();

        // Copy (cascade) user to t1 DB
        $centralUser->tenants()->attach('t1');
        $centralUser->tenants()->attach('t2');
        $centralUser->tenants()->attach('t3');

        $t3->run(function () {
            User::first()->update([
                'name' => 'John 3',
                'role' => 'employee', // unsynced
            ]);

            $this->assertSame('employee', User::first()->role);
        });

        // Check that change was cascaded to other tenants
        $t1->run($check = function () {
            $user = User::first();

            $this->assertSame('John 3', $user->name); // synced
            $this->assertSame('commenter', $user->role); // unsynced
        });
        $t2->run($check);

        // Check that change bubbled up to central DB
        $this->assertSame(1, CentralUser::count());
        $centralUser = CentralUser::first();
        $this->assertSame('John 3', $centralUser->name); // synced
        $this->assertSame('commenter', $centralUser->role); // unsynced
    }

    /** @test */
    public function global_id_is_generated_using_id_generator_when_its_not_supplied()
    {
        $user = CentralUser::create([
            'name' => 'John Doe',
            'email' => 'john@doe',
            'password' => 'secret',
            'role' => 'employee',
        ]);

        $this->assertNotNull($user->global_id);
    }

    /** @test */
    public function when_the_resource_doesnt_exist_in_the_tenant_db_non_synced_columns_will_cascade_too()
    {
        $centralUser = CentralUser::create([
            'name' => 'John Doe',
            'email' => 'john@doe',
            'password' => 'secret',
            'role' => 'employee',
        ]);

        $t1 = Tenant::create([
            'id' => 't1',
        ]);

        $this->migrateTenants();

        $centralUser->tenants()->attach('t1');

        $t1->run(function () {
            $this->assertSame('employee', User::first()->role);
        });
    }

    /** @test */
    public function when_the_resource_doesnt_exist_in_the_central_db_non_synced_columns_will_bubble_up_too()
    {
        $t1 = Tenant::create([
            'id' => 't1',
        ]);

        $this->migrateTenants();

        $t1->run(function () {
            User::create([
                'name' => 'John Doe',
                'email' => 'john@doe',
                'password' => 'secret',
                'role' => 'employee',
            ]);
        });

        $this->assertSame('employee', CentralUser::first()->role);
    }

    /** @test */
    public function the_listener_can_be_queued()
    {
        Queue::fake();
        UpdateSyncedResource::$shouldQueue = true;

        $t1 = Tenant::create([
            'id' => 't1',
        ]);

        $this->migrateTenants();

        Queue::assertNothingPushed();

        $t1->run(function () {
            User::create([
                'name' => 'John Doe',
                'email' => 'john@doe',
                'password' => 'secret',
                'role' => 'employee',
            ]);
        });

        Queue::assertPushed(CallQueuedListener::class, function (CallQueuedListener $job) {
            return $job->class === UpdateSyncedResource::class;
        });
    }

    /** @test */
    public function an_event_is_fired_for_all_touched_resources()
    {
        Event::fake([SyncedResourceChangedInForeignDatabase::class]);

        // create shared resource
        $centralUser = CentralUser::create([
            'global_id' => 'acme',
            'name' => 'John Doe',
            'email' => 'john@localhost',
            'password' => 'secret',
            'role' => 'commenter', // unsynced
        ]);

        $t1 = Tenant::create([
            'id' => 't1',
        ]);
        $t2 = Tenant::create([
            'id' => 't2',
        ]);
        $t3 = Tenant::create([
            'id' => 't3',
        ]);
        $this->migrateTenants();

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
            User::first()->update([
                'name' => 'John 3',
                'role' => 'employee', // unsynced
            ]);

            $this->assertSame('employee', User::first()->role);
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

        // todo update in global
    }
}

class Tenant extends Models\Tenant
{
    public function users()
    {
        return $this->belongsToMany(CentralUser::class, 'tenant_users', 'tenant_id', 'global_user_id')
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
        return $this->belongsToMany(Tenant::class, 'tenant_users', 'global_user_id', 'tenant_id')
            ->using(TenantPivot::class);
    }

    public function getTenantModelName(): string
    {
        return User::class;
    }

    public function getTenantIdColumnInMapTable(): string
    {
        return 'tenant_id';
    }

    public function getGlobalIdentifierKey(): string
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
            'name',
            'password',
            'email',
        ];
    }
}

class User extends Model implements Syncable
{
    use ResourceSyncing;

    protected $guarded = [];
    public $timestamps = false;

    public function getGlobalIdentifierKey(): string
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
            'name',
            'password',
            'email',
        ];
    }
}