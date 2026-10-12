<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Events\TenantDeleted;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\DeleteDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Stancl\Tenancy\Jobs\SeedDatabase;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Foundation\Auth\User as Authenticable;
use Stancl\Tenancy\Tests\Etc\TestSeeder;

beforeEach($cleanup = function () {
    DeleteDatabase::$ignoreFailures = false;
    DeleteDatabase::$skipWhenCreateDatabaseIsFalse = true;
});

afterEach($cleanup);

test('database can be created after tenant creation', function () {
    config(['tenancy.database.template_tenant_connection' => 'mysql']);

    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    $tenant = Tenant::create();
    $manager = $tenant->database()->manager();

    expect($manager->databaseExists($tenant->database()->getName()))->toBeTrue();
});

test('database can be migrated after tenant creation', function () {
    Event::listen(TenantCreated::class, JobPipeline::make([
        CreateDatabase::class,
        MigrateDatabase::class,
    ])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    $tenant = Tenant::create();

    $tenant->run(function () {
        expect(Schema::hasTable('users'))->toBeTrue();
    });
});

test('database can be seeded after tenant creation', function () {
    config(['tenancy.seeder_parameters' => [
        '--class' => TestSeeder::class,
    ]]);

    Event::listen(TenantCreated::class, JobPipeline::make([
        CreateDatabase::class,
        MigrateDatabase::class,
        SeedDatabase::class,
    ])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    $tenant = Tenant::create();

    $tenant->run(function () {
        expect(User::first()->name)->toBe('Seeded User');
    });
});

test('the pipeline stops when migrating the tenant database fails without an exception', function () {
    // The tenant's database doesn't exist (CreateDatabase isn't in the pipeline), so
    // tenants:migrate fails, but --skip-failing makes it return 1 instead of throwing
    config([
        'tenancy.bootstrappers' => [DatabaseTenancyBootstrapper::class],
        'tenancy.migration_parameters' => ['--force' => true, '--skip-failing' => true],
    ]);

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);

    RecordJobRan::$ran = false;

    Event::listen(TenantCreated::class, JobPipeline::make([
        MigrateDatabase::class,
        RecordJobRan::class,
    ])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    expect(fn () => Tenant::create())->toThrow(RuntimeException::class, 'tenants:migrate');
    expect(RecordJobRan::$ran)->toBeFalse();
});

test('the pipeline stops when seeding the tenant database fails without an exception', function () {
    // tenants:seed only returns a nonzero exit code without throwing when it can't confirm
    // running in production, which would prompt for confirmation in tests. Replace the
    // command with one that just fails instead.
    app(ConsoleKernel::class)->registerCommand(new ClosureCommand('tenants:seed {--tenants=*} {--with-pending=}', fn () => 1));

    RecordJobRan::$ran = false;

    Event::listen(TenantCreated::class, JobPipeline::make([
        CreateDatabase::class,
        MigrateDatabase::class,
        SeedDatabase::class,
        RecordJobRan::class,
    ])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    expect(fn () => Tenant::create())->toThrow(RuntimeException::class, 'tenants:seed');
    expect(RecordJobRan::$ran)->toBeFalse();
});

test('custom job can be added to the pipeline', function () {
    config(['tenancy.seeder_parameters' => [
        '--class' => TestSeeder::class,
    ]]);

    Event::listen(TenantCreated::class, JobPipeline::make([
        CreateDatabase::class,
        MigrateDatabase::class,
        SeedDatabase::class,
        CreateSuperuser::class,
    ])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    $tenant = Tenant::create();

    $tenant->run(function () {
        expect(User::all()[1]->name)->toBe('Foo');
    });
});

test('database can be deleted after tenant deletion', function () {
    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    Event::listen(TenantDeleted::class, JobPipeline::make([DeleteDatabase::class])->send(function (TenantDeleted $event) {
        return $event->tenant;
    })->toListener());

    $tenant = Tenant::create();
    $manager = $tenant->database()->manager();

    expect($manager->databaseExists($tenant->database()->getName()))->toBeTrue();

    $tenant->delete();

    expect($manager->databaseExists($tenant->database()->getName()))->toBeFalse();
});

test('database deletion is skipped when create_database is false', function (bool $skipWhenCreateDatabaseIsFalse) {
    Event::listen(TenantDeleted::class, JobPipeline::make([DeleteDatabase::class])->send(function (TenantDeleted $event) {
        return $event->tenant;
    })->toListener());

    // create_database=false means no DB is created (e.g. tenant uses a pre-existing DB)
    // On deletion, DeleteDatabase should skip rather than attempting DROP DATABASE on a non-existent DB
    $tenant = Tenant::create(['tenancy_create_database' => false, 'tenancy_db_name' => 'non_existing_db']);

    $manager = $tenant->database()->manager();
    expect($manager->databaseExists($tenant->database()->getName()))->toBeFalse();

    DeleteDatabase::$skipWhenCreateDatabaseIsFalse = $skipWhenCreateDatabaseIsFalse;

    if ($skipWhenCreateDatabaseIsFalse) {
        $tenant->delete(); // no exception
    } else {
        expect(fn () => $tenant->delete())->toThrow(QueryException::class, "database doesn't exist");
    }

    expect($manager->databaseExists($tenant->database()->getName()))->toBeFalse();
})->with([true, false]);

test('database deletion failure is ignored when ignoreFailures is true', function (bool $ignoreFailures) {
    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    Event::listen(TenantDeleted::class, JobPipeline::make([DeleteDatabase::class])->send(function (TenantDeleted $event) {
        return $event->tenant;
    })->toListener());

    DeleteDatabase::$ignoreFailures = $ignoreFailures;

    $tenant = Tenant::create();
    $manager = $tenant->database()->manager();
    expect($manager->databaseExists($tenant->database()->getName()))->toBeTrue();

    $manager->deleteDatabase($tenant); // manually delete so the job fails
    expect($manager->databaseExists($tenant->database()->getName()))->toBeFalse();

    if ($ignoreFailures) {
        $tenant->delete(); // no exception
    } else {
        expect(fn () => $tenant->delete())->toThrow(QueryException::class, "database doesn't exist");
    }
})->with([true, false]);

class User extends Authenticable
{
    protected $guarded = [];
}

class CreateSuperuser
{
    protected $tenant;

    public function __construct(Tenant $tenant)
    {
        $this->tenant = $tenant;
    }

    public function handle()
    {
        $this->tenant->run(function () {
            User::create(['name' => 'Foo', 'email' => 'foo@bar.com', 'password' => 'secret']);
        });
    }
}

class RecordJobRan
{
    public static bool $ran = false;

    public function handle(): void
    {
        static::$ran = true;
    }
}
