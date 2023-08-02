<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Contracts\Syncable;
use Stancl\Tenancy\Contracts\SyncMaster;
use Stancl\Tenancy\Database\Concerns\CentralConnection;
use Stancl\Tenancy\Database\Concerns\ResourceSyncing;
use Stancl\Tenancy\Database\DatabaseConfig;
use Stancl\Tenancy\Database\Models\TenantMorphPivot;
use Stancl\Tenancy\Database\Models\TenantPivot;
use Stancl\Tenancy\Events\SyncedResourceSaved;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Listeners\UpdateSyncedResource;
use Stancl\Tenancy\Tests\Etc\Tenant;

beforeEach(function () {
    config([
        'tenancy.bootstrappers' => [
            DatabaseTenancyBootstrapper::class,
        ],
        'tenancy.models.tenant' => ResourceTenantUsingPolymorphic::class,
    ]);

    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    DatabaseConfig::generateDatabaseNamesUsing(function () {
        return 'db' . Str::random(16);
    });

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);

    // todo1 Is this cleanup needed?
    UpdateSyncedResource::$shouldQueue = false; // Global state cleanup
    Event::listen(SyncedResourceSaved::class, UpdateSyncedResource::class);

    // Run migrations on central connection
    pest()->artisan('migrate', [
        '--path' => [
            __DIR__ . '/../assets/resource-syncing-migrations',
            __DIR__ . '/Etc/synced_resource_migrations/users',
            __DIR__ . '/Etc/synced_resource_migrations/companies',
        ],
        '--realpath' => true,
    ])->assertExitCode(0);
});

test('resource syncing works using a single pivot table for multiple models when syncing from central to tenant', function () {
    $tenant1 = ResourceTenantUsingPolymorphic::create(['id' => 't1']);
    migrateUsersTableForTenants();

    $centralUser = CentralUserUsingPolymorphic::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'password',
        'role' => 'commenter',
    ]);

    $tenant1->run(function () {
        expect(TenantUserUsingPolymorphic::all())->toHaveCount(0);
    });

    $centralUser->tenants()->attach('t1');

    // Assert `tenants` are accessible
    expect($centralUser->tenants->pluck('id')->toArray())->toBe(['t1']);

    // Users are accessible from tenant
    expect($tenant1->users()->pluck('email')->toArray())->toBe(['john@localhost']);

    // Assert User resource is synced
    $tenant1->run(function () use ($centralUser) {
        $tenantUser = TenantUserUsingPolymorphic::first()->toArray();
        $centralUser = $centralUser->withoutRelations()->toArray();
        unset($centralUser['id'], $tenantUser['id']);

        expect($tenantUser)->toBe($centralUser);
    });

    $tenant2 = ResourceTenantUsingPolymorphic::create(['id' => 't2']);
    migrateCompaniesTableForTenants();

    $centralCompany = CentralCompanyUsingPolymorphic::create([
        'global_id' => 'acme',
        'name' => 'ArchTech',
        'email' => 'archtech@localhost',
    ]);

    $tenant2->run(function () {
        expect(TenantCompanyUsingPolymorphic::all())->toHaveCount(0);
    });

    $centralCompany->tenants()->attach('t2');

    // Assert `tenants` are accessible
    expect($centralCompany->tenants->pluck('id')->toArray())->toBe(['t2']);

    // Companies are accessible from tenant
    expect($tenant2->companies()->pluck('email')->toArray())->toBe(['archtech@localhost']);

    // Assert Company resource is synced
    $tenant2->run(function () use ($centralCompany) {
        $tenantCompany = TenantCompanyUsingPolymorphic::first()->toArray();
        $centralCompany = $centralCompany->withoutRelations()->toArray();

        unset($centralCompany['id'], $tenantCompany['id']);

        expect($tenantCompany)->toBe($centralCompany);
    });
});

test('resource syncing works using a single pivot table for multiple models when syncing from tenant to central', function () {
    $tenant1 = ResourceTenantUsingPolymorphic::create(['id' => 't1']);
    migrateUsersTableForTenants();

    tenancy()->initialize($tenant1);

    $tenantUser = TenantUserUsingPolymorphic::create([
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'password',
        'role' => 'commenter',
    ]);

    tenancy()->end();

    // Assert User resource is synced
    $centralUser = CentralUserUsingPolymorphic::first();

    // Assert `tenants` are accessible
    expect($centralUser->tenants->pluck('id')->toArray())->toBe(['t1']);

    // Users are accessible from tenant
    expect($tenant1->users()->pluck('email')->toArray())->toBe(['john@localhost']);

    $centralUser = $centralUser->withoutRelations()->toArray();
    $tenantUser = $tenantUser->toArray();
    unset($centralUser['id'], $tenantUser['id']);

    // array keys use a different order here
    expect($tenantUser)->toEqualCanonicalizing($centralUser);

    $tenant2 = ResourceTenantUsingPolymorphic::create(['id' => 't2']);
    migrateCompaniesTableForTenants();

    tenancy()->initialize($tenant2);

    $tenantCompany = TenantCompanyUsingPolymorphic::create([
        'global_id' => 'acme',
        'name' => 'tenant comp',
        'email' => 'company@localhost',
    ]);

    tenancy()->end();

    // Assert Company resource is synced
    $centralCompany = CentralCompanyUsingPolymorphic::first();

    // Assert `tenants` are accessible
    expect($centralCompany->tenants->pluck('id')->toArray())->toBe(['t2']);

    // Companies are accessible from tenant
    expect($tenant2->companies()->pluck('email')->toArray())->toBe(['company@localhost']);

    $centralCompany = $centralCompany->withoutRelations()->toArray();
    $tenantCompany = $tenantCompany->toArray();
    unset($centralCompany['id'], $tenantCompany['id']);

    expect($tenantCompany)->toBe($centralCompany);
});

test('right resources are accessible from the tenant', function () {
    $tenant1 = ResourceTenantUsingPolymorphic::create(['id' => 't1']);
    $tenant2 = ResourceTenantUsingPolymorphic::create(['id' => 't2']);
    migrateUsersTableForTenants();

    $user1 = CentralUserUsingPolymorphic::create([
        'global_id' => 'user1',
        'name' => 'user1',
        'email' => 'user1@localhost',
        'password' => 'password',
        'role' => 'commenter',
    ]);

    $user2 = CentralUserUsingPolymorphic::create([
        'global_id' => 'user2',
        'name' => 'user2',
        'email' => 'user2@localhost',
        'password' => 'password',
        'role' => 'commenter',
    ]);

    $user3 = CentralUserUsingPolymorphic::create([
        'global_id' => 'user3',
        'name' => 'user3',
        'email' => 'user3@localhost',
        'password' => 'password',
        'role' => 'commenter',
    ]);

    $user1->tenants()->attach('t1');
    $user2->tenants()->attach('t1');
    $user3->tenants()->attach('t2');

    expect($tenant1->users()->pluck('email')->toArray())->toBe([$user1->email, $user2->email]);
    expect($tenant2->users()->pluck('email')->toArray())->toBe([$user3->email]);
});

function migrateCompaniesTableForTenants(): void
{
    pest()->artisan('tenants:migrate', [
        '--path' => __DIR__ . '/Etc/synced_resource_migrations/companies',
        '--realpath' => true,
    ])->assertExitCode(0);
}

// Tenant model used for resource syncing setup
class ResourceTenantUsingPolymorphic extends Tenant
{
    public function users(): MorphToMany
    {
        return $this->morphedByMany(CentralUserUsingPolymorphic::class, 'tenant_resources', 'tenant_resources', 'tenant_id', 'resource_global_id', 'id', 'global_id')
            ->using(TenantMorphPivot::class);
    }

    public function companies(): MorphToMany
    {
        return $this->morphedByMany(CentralCompanyUsingPolymorphic::class, 'tenant_resources', 'tenant_resources', 'tenant_id', 'resource_global_id', 'id', 'global_id')
            ->using(TenantMorphPivot::class);
    }
}

class CentralUserUsingPolymorphic extends Model implements SyncMaster
{
    use ResourceSyncing, CentralConnection;

    protected $guarded = [];

    public $timestamps = false;

    public $table = 'users';

    public function getTenantModelName(): string
    {
        return TenantUserUsingPolymorphic::class;
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

class TenantUserUsingPolymorphic extends Model implements Syncable
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
        return CentralUserUsingPolymorphic::class;
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

class CentralCompanyUsingPolymorphic extends Model implements SyncMaster
{
    use ResourceSyncing, CentralConnection;

    protected $guarded = [];

    public $timestamps = false;

    public $table = 'companies';

    public function getTenantModelName(): string
    {
        return TenantCompanyUsingPolymorphic::class;
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
            'email',
        ];
    }
}

class TenantCompanyUsingPolymorphic extends Model implements Syncable
{
    use ResourceSyncing;

    protected $table = 'companies';

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
        return CentralCompanyUsingPolymorphic::class;
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
