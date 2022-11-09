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
            __DIR__ . '/Etc/synced_resource_migrations/companies',
        ],
        '--realpath' => true,
    ])->assertExitCode(0);
});

test('resource syncing works using a single pivot table for multiple models when syncing from central to tenant', function () {
    $tenant1 = ResourceTenantForPolymorphic::create(['id' => 't1']);
    migrateUsersTableForTenants();

    $centralUser = CentralUserForPolymorphic::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'password',
        'role' => 'commenter',
    ]);

    $tenant1->run(function () {
        expect(ResourceUserForPolymorphic::all())->toHaveCount(0);
    });

    $centralUser->tenants()->attach('t1');

    // Assert User resource is synced
    $tenant1->run(function () use ($centralUser) {
        $resourceUser = ResourceUserForPolymorphic::first()->only(['name', 'email', 'password', 'role']);
        $centralUser = $centralUser->only(['name', 'email', 'password', 'role']);

        expect($resourceUser)->toBe($centralUser);
    });

    $tenant2 = ResourceTenantForPolymorphic::create(['id' => 't2']);
    migrateCompaniesTableForTenants();

    $centralCompany = CentralCompanyForPolymorphic::create([
        'global_id' => 'acme',
        'name' => 'ArchTech',
        'email' => 'archtech@localhost',
    ]);

    $tenant2->run(function () {
        expect(ResourceCompanyForPolymorphic::all())->toHaveCount(0);
    });

    $centralCompany->tenants()->attach('t2');

    // Assert Company resource is synced
    $tenant2->run(function () use ($centralCompany) {
        $resourceCompany = ResourceCompanyForPolymorphic::first()->only(['name', 'email']);
        $centralCompany = $centralCompany->only(['name', 'email']);

        expect($resourceCompany)->toBe($centralCompany);
    });
});

test('resource syncing works using a single pivot table for multiple models when syncing from tenant to central', function () {
    $tenant1 = ResourceTenantForPolymorphic::create(['id' => 't1']);
    migrateUsersTableForTenants();

    tenancy()->initialize($tenant1);

    $resourceUser = ResourceUserForPolymorphic::create([
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'password',
        'role' => 'commenter',
    ]);

    tenancy()->end();

    // Assert User resource is synced
    $centralUser = CentralUserForPolymorphic::first()->only(['name', 'email', 'password', 'role']);
    $resourceUser = $resourceUser->only(['name', 'email', 'password', 'role']);
    expect($resourceUser)->toBe($centralUser);

    $tenant2 = ResourceTenantForPolymorphic::create(['id' => 't2']);
    migrateCompaniesTableForTenants();

    tenancy()->initialize($tenant2);

    $resourceCompany = ResourceCompanyForPolymorphic::create([
        'global_id' => 'acme',
        'name' => 'tenant comp',
        'email' => 'company@localhost',
    ]);

    tenancy()->end();

    // Assert Company resource is synced
    $centralCompany = CentralCompanyForPolymorphic::first()->only(['name', 'email']);
    $resourceCompany = $resourceCompany->only(['name', 'email']);
    expect($resourceCompany)->toBe($centralCompany);
});

function migrateCompaniesTableForTenants(): void
{
    pest()->artisan('tenants:migrate', [
        '--path' => __DIR__ . '/Etc/synced_resource_migrations/companies',
        '--realpath' => true,
    ])->assertExitCode(0);
}

// todo better names for resource syncing setup here

class ResourceTenantForPolymorphic extends Tenant
{
    public function users(): MorphToMany
    {
        return $this->morphedByMany(CentralUserForPolymorphic::class, 'tenant_resources', 'tenant_resources', 'tenant_id', 'resource_global_id', 'id', 'global_id')
            ->using(TenantPivot::class);
    }

    public function companies(): MorphToMany
    {
        return $this->morphedByMany(CentralCompanyForPolymorphic::class, 'tenant_resources', 'tenant_resources', 'tenant_id', 'resource_global_id', 'id', 'global_id')
            ->using(TenantPivot::class);
    }
}

class CentralUserForPolymorphic extends Model implements SyncMaster
{
    use ResourceSyncing, CentralConnection;

    protected $guarded = [];

    public $timestamps = false;

    public $table = 'users';

    // override method to provide different tenant
    public function getResourceTenantModelName(): string
    {
        return ResourceTenantForPolymorphic::class;
    }

    public function getTenantModelName(): string
    {
        return ResourceUserForPolymorphic::class;
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

class ResourceUserForPolymorphic extends Model implements Syncable
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
        return CentralUserForPolymorphic::class;
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

class CentralCompanyForPolymorphic extends Model implements SyncMaster
{
    use ResourceSyncing, CentralConnection;

    protected $guarded = [];

    public $timestamps = false;

    public $table = 'companies';

    // override method to provide different tenant
    public function getResourceTenantModelName(): string
    {
        return ResourceTenantForPolymorphic::class;
    }

    public function getTenantModelName(): string
    {
        return ResourceCompanyForPolymorphic::class;
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

class ResourceCompanyForPolymorphic extends Model implements Syncable
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
        return CentralCompanyForPolymorphic::class;
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

