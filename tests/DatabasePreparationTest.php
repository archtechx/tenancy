<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Database\Seeder;
use Illuminate\Foundation\Auth\User as Authenticable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Stancl\Tenancy\Jobs\SeedDatabase;
use Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager;
use Stancl\Tenancy\Tests\Etc\Tenant;

class DatabasePreparationTest extends TestCase
{
    /** @test */
    public function database_can_be_created_after_tenant_creation()
    {
        config(['tenancy.database.template_tenant_connection' => 'mysql']);

        Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
            return $event->tenant;
        })->toListener());

        $tenant = Tenant::create();

        $manager = app(MySQLDatabaseManager::class);
        $manager->setConnection('mysql');

        $this->assertTrue($manager->databaseExists($tenant->database()->getName()));
    }

    /** @test */
    public function database_can_be_migrated_after_tenant_creation()
    {
        Event::listen(TenantCreated::class, JobPipeline::make([
            CreateDatabase::class,
            MigrateDatabase::class,
        ])->send(function (TenantCreated $event) {
            return $event->tenant;
        })->toListener());

        $tenant = Tenant::create();

        $tenant->run(function () {
            $this->assertTrue(Schema::hasTable('users'));
        });
    }

    /** @test */
    public function database_can_be_seeded_after_tenant_creation()
    {
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
            $this->assertSame('Seeded User', User::first()->name);
        });
    }

    /** @test */
    public function custom_job_can_be_added_to_the_pipeline()
    {
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
            $this->assertSame('Foo', User::all()[1]->name);
        });
    }
}

class User extends Authenticable
{
    protected $guarded = [];
}

class TestSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::table('users')->insert([
            'name' => 'Seeded User',
            'email' => 'seeded@user',
            'password' => bcrypt('password'),
        ]);
    }
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
