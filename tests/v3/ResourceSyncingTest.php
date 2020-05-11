<?php

namespace Stancl\Tenancy\Tests\v3;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Contracts\Syncable;
use Stancl\Tenancy\Contracts\SyncMaster;
use Stancl\Tenancy\Database\Models\Concerns\CentralConnection;
use Stancl\Tenancy\Database\Models\Concerns\ResourceSyncing;
use Stancl\Tenancy\Database\Models\Tenant;
use Stancl\Tenancy\Events\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Events\Listeners\JobPipeline;
use Stancl\Tenancy\Events\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Events\Listeners\UpdateSyncedResource;
use Stancl\Tenancy\Events\SyncedResourceSaved;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\TenancyBootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Tests\TestCase;

class ResourceSyncingTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        config(['tenancy.bootstrappers' => [
            DatabaseTenancyBootstrapper::class
        ]]);

        Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
            return $event->tenant;
        })->toListener());

        Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
        Event::listen(TenancyEnded::class, RevertToCentralContext::class);
    }

    /** @test */
    public function an_event_is_triggered_when_a_synced_resource_is_changed()
    {
        $this->loadLaravelMigrations();

        Event::fake([SyncedResourceSaved::class]);

        $user = User::create([
            'name' => 'Foo',
            'email' => 'foo@email.com',
            'password' => 'secret',
        ]);

        Event::assertDispatched(SyncedResourceSaved::class, function (SyncedResourceSaved $event) use ($user) {
            return $event->model === $user;
        });
    }
    
    /** @test */
    public function only_the_synced_columns_are_updated_in_the_central_db()
    {
        Event::listen(SyncedResourceSaved::class, UpdateSyncedResource::class);

        $this->artisan('migrate', [
            '--path' => [
                __DIR__ . '/../Etc/synced_resource_migrations',
                __DIR__ . '/../Etc/synced_resource_migrations/users'
            ],
            '--realpath' => true,
        ])->assertExitCode(0);

        // Create user in central DB
        $user = CentralUser::create([
            'global_id' => 'acme',
            'name' => 'John Doe',
            'email' => 'john@localhost',
            'password' => 'secret',
            'role' => 'superadmin', // unsynced
        ]);

        $tenant = Tenant::create();
        $this->artisan('tenants:migrate', [
            '--path' => __DIR__ . '/../Etc/synced_resource_migrations/users',
            '--realpath' => true,
        ])->assertExitCode(0);

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
    public function the_synced_columns_are_updated_in_other_tenant_dbs_where_the_resource_exists()
    {
        // todo
    }

    /** @test */
    public function global_id_is_generated_using_id_generatr_when_its_not_supplied()
    {
        // todo
    }
}

class CentralUser extends Model implements SyncMaster
{
    use ResourceSyncing, CentralConnection;

    protected $guarded = [];
    public $timestamps = false;
    public $table = 'users';

    public function tenants()
    {
        return $this->belongsToMany(Tenant::class, 'tenant_users', 'global_user_id', 'global_user_id');
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