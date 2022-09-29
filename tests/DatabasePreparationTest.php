<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Stancl\Tenancy\Jobs\SeedDatabase;
use Stancl\Tenancy\Database\TenantDatabaseManagers\MySQLDatabaseManager;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Foundation\Auth\User as Authenticable;
use Stancl\Tenancy\Tests\Etc\TestSeeder;

test('database can be created after tenant creation', function () {
    config(['tenancy.database.template_tenant_connection' => 'mysql']);

    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    $tenant = Tenant::create();

    $manager = app(MySQLDatabaseManager::class);
    $manager->setConnection('mysql');

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
