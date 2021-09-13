<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Tests\Etc\ExampleSeeder;
use Stancl\Tenancy\Tests\Etc\Tenant;

class CommandsTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
            return $event->tenant;
        })->toListener());

        config(['tenancy.bootstrappers' => [
            DatabaseTenancyBootstrapper::class,
        ],
        'tenancy.filesystem.suffix_base' => 'tenant-',
        'tenancy.filesystem.root_override.public' => '%storage_path%/app/public/',
        'tenancy.filesystem.url_override.public' => 'public-%tenant_id%'
        ]);

        Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
        Event::listen(TenancyEnded::class, RevertToCentralContext::class);
    }

    public function tearDown(): void
    {
        parent::tearDown();

        // Cleanup tenancy config cache
        if (file_exists(base_path('config/tenancy.php'))) {
            unlink(base_path('config/tenancy.php'));
        }
    }

    /** @test */
    public function migrate_command_doesnt_change_the_db_connection()
    {
        $this->assertFalse(Schema::hasTable('users'));

        $old_connection_name = app(\Illuminate\Database\DatabaseManager::class)->connection()->getName();
        Artisan::call('tenants:migrate');
        $new_connection_name = app(\Illuminate\Database\DatabaseManager::class)->connection()->getName();

        $this->assertFalse(Schema::hasTable('users'));
        $this->assertEquals($old_connection_name, $new_connection_name);
        $this->assertNotEquals('tenant', $new_connection_name);
    }

    /** @test */
    public function migrate_command_works_without_options()
    {
        $tenant = Tenant::create();

        $this->assertFalse(Schema::hasTable('users'));
        Artisan::call('tenants:migrate');
        $this->assertFalse(Schema::hasTable('users'));

        tenancy()->initialize($tenant);

        $this->assertTrue(Schema::hasTable('users'));
    }

    /** @test */
    public function migrate_command_works_with_tenants_option()
    {
        $tenant = Tenant::create();
        Artisan::call('tenants:migrate', [
            '--tenants' => [$tenant['id']],
        ]);

        $this->assertFalse(Schema::hasTable('users'));
        tenancy()->initialize(Tenant::create());
        $this->assertFalse(Schema::hasTable('users'));

        tenancy()->initialize($tenant);
        $this->assertTrue(Schema::hasTable('users'));
    }

    /** @test */
    public function rollback_command_works()
    {
        $tenant = Tenant::create();
        Artisan::call('tenants:migrate');
        $this->assertFalse(Schema::hasTable('users'));

        tenancy()->initialize($tenant);

        $this->assertTrue(Schema::hasTable('users'));
        Artisan::call('tenants:rollback');
        $this->assertFalse(Schema::hasTable('users'));
    }

    /** @test */
    public function seed_command_works()
    {
        $this->markTestIncomplete();
    }

    /** @test */
    public function database_connection_is_switched_to_default()
    {
        $originalDBName = DB::connection()->getDatabaseName();

        Artisan::call('tenants:migrate');
        $this->assertSame($originalDBName, DB::connection()->getDatabaseName());

        Artisan::call('tenants:seed', ['--class' => ExampleSeeder::class]);
        $this->assertSame($originalDBName, DB::connection()->getDatabaseName());

        Artisan::call('tenants:rollback');
        $this->assertSame($originalDBName, DB::connection()->getDatabaseName());

        $this->run_commands_works();
        $this->assertSame($originalDBName, DB::connection()->getDatabaseName());
    }

    /** @test */
    public function database_connection_is_switched_to_default_when_tenancy_has_been_initialized()
    {
        tenancy()->initialize(Tenant::create());

        $this->database_connection_is_switched_to_default();
    }

    /** @test */
    public function run_commands_works()
    {
        $id = Tenant::create()->getTenantKey();

        Artisan::call('tenants:migrate', ['--tenants' => [$id]]);

        $this->artisan("tenants:run foo --tenants=$id --argument='a=foo' --option='b=bar' --option='c=xyz'")
            ->expectsOutput("User's name is Test command")
            ->expectsOutput('foo')
            ->expectsOutput('xyz');
    }

    /** @test */
    public function install_command_works()
    {
        if (! is_dir($dir = app_path('Http'))) {
            mkdir($dir, 0777, true);
        }
        if (! is_dir($dir = base_path('routes'))) {
            mkdir($dir, 0777, true);
        }

        $this->artisan('tenancy:install');
        $this->assertFileExists(base_path('routes/tenant.php'));
        $this->assertFileExists(base_path('config/tenancy.php'));
        $this->assertFileExists(app_path('Providers/TenancyServiceProvider.php'));
        $this->assertFileExists(database_path('migrations/2019_09_15_000010_create_tenants_table.php'));
        $this->assertFileExists(database_path('migrations/2019_09_15_000020_create_domains_table.php'));
        $this->assertDirectoryExists(database_path('migrations/tenant'));
    }

    /** @test */
    public function migrate_fresh_command_works()
    {
        $tenant = Tenant::create();

        $this->assertFalse(Schema::hasTable('users'));
        Artisan::call('tenants:migrate-fresh');
        $this->assertFalse(Schema::hasTable('users'));

        tenancy()->initialize($tenant);

        $this->assertTrue(Schema::hasTable('users'));

        $this->assertFalse(DB::table('users')->exists());
        DB::table('users')->insert(['name' => 'xxx', 'password' => bcrypt('password'), 'email' => 'foo@bar.xxx']);
        $this->assertTrue(DB::table('users')->exists());

        // test that db is wiped
        Artisan::call('tenants:migrate-fresh');
        $this->assertFalse(DB::table('users')->exists());
    }

    /** @test */
    public function run_command_with_array_of_tenants_works()
    {
        $tenantId1 = Tenant::create()->getTenantKey();
        $tenantId2 = Tenant::create()->getTenantKey();
        Artisan::call('tenants:migrate-fresh');

        $this->artisan("tenants:run foo --tenants=$tenantId1 --tenants=$tenantId2 --argument='a=foo' --option='b=bar' --option='c=xyz'")
            ->expectsOutput('Tenant: ' . $tenantId1)
            ->expectsOutput('Tenant: ' . $tenantId2);
    }

    /** @test */
    public function link_command_works()
    {
        $tenantId1 = Tenant::create()->getTenantKey();
        $tenantId2 = Tenant::create()->getTenantKey();
        Artisan::call('tenants:link');

        $this->assertDirectoryExists(storage_path("tenant-$tenantId1/app/public"));
        $this->assertEquals(storage_path("tenant-$tenantId1/app/public/"), readlink(public_path("public-$tenantId1")));

        $this->assertDirectoryExists(storage_path("tenant-$tenantId2/app/public"));
        $this->assertEquals(storage_path("tenant-$tenantId2/app/public/"), readlink(public_path("public-$tenantId2")));

        Artisan::call('tenants:link', [
            '--remove' => true,
        ]);

        $this->assertDirectoryDoesNotExist(public_path("public-$tenantId1"));
        $this->assertDirectoryDoesNotExist(public_path("public-$tenantId2"));
    }

    /** @test */
    public function link_command_with_tenant_specified_works()
    {
        $tenant_key = Tenant::create()->getTenantKey();
        Artisan::call('tenants:link', [
            '--tenants' => [$tenant_key],
        ]);

        $this->assertDirectoryExists(storage_path("tenant-$tenant_key/app/public"));
        $this->assertEquals(storage_path("tenant-$tenant_key/app/public/"), readlink(public_path("public-$tenant_key")));

        Artisan::call('tenants:link', [
            '--remove' => true,
            '--tenants' => [$tenant_key],
        ]);

        $this->assertDirectoryDoesNotExist(public_path("public-$tenant_key"));
    }
}
