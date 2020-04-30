<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Tenant;
use Stancl\Tenancy\Tests\Etc\ExampleSeeder;

class CommandsTest extends TestCase
{
    public $autoInitTenancy = false;

    public function setUp(): void
    {
        parent::setUp();

        config(['tenancy.migration_paths', [database_path('../migrations')]]);
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
        $this->assertFalse(Schema::hasTable('users'));
        Artisan::call('tenants:migrate');
        $this->assertFalse(Schema::hasTable('users'));
        tenancy()->init('test.localhost');
        $this->assertTrue(Schema::hasTable('users'));
    }

    /** @test */
    public function migrate_command_works_with_tenants_option()
    {
        $tenant = Tenant::new()->withDomains(['test2.localhost'])->save();
        Artisan::call('tenants:migrate', [
            '--tenants' => [$tenant['id']],
        ]);

        $this->assertFalse(Schema::hasTable('users'));
        tenancy()->init('test.localhost');
        $this->assertFalse(Schema::hasTable('users'));

        tenancy()->init('test2.localhost');
        $this->assertTrue(Schema::hasTable('users'));
    }

    /** @test */
    public function rollback_command_works()
    {
        Artisan::call('tenants:migrate');
        $this->assertFalse(Schema::hasTable('users'));
        tenancy()->init('test.localhost');
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
        tenancy()->init('test.localhost');

        $this->database_connection_is_switched_to_default();
    }

    /** @test */
    public function run_commands_works()
    {
        $id = Tenant::new()->withDomains(['run.localhost'])->save()['id'];

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

        if (app()->version()[0] === '6') {
            file_put_contents(app_path('Http/Kernel.php'), file_get_contents(__DIR__ . '/Etc/defaultHttpKernelv6.stub'));
        } else {
            file_put_contents(app_path('Http/Kernel.php'), file_get_contents(__DIR__ . '/Etc/defaultHttpKernelv7.stub'));
        }

        $this->artisan('tenancy:install')
            ->expectsQuestion('Do you wish to publish the migrations that create these tables?', 'yes');
        $this->assertFileExists(base_path('routes/tenant.php'));
        $this->assertFileExists(base_path('config/tenancy.php'));
        $this->assertFileExists(database_path('migrations/2019_09_15_000010_create_tenants_table.php'));
        $this->assertFileExists(database_path('migrations/2019_09_15_000020_create_domains_table.php'));
        $this->assertDirectoryExists(database_path('migrations/tenant'));

        if (app()->version()[0] === '6') {
            $this->assertSame(file_get_contents(__DIR__ . '/Etc/modifiedHttpKernelv6.stub'), file_get_contents(app_path('Http/Kernel.php')));
        } else {
            $this->assertSame(file_get_contents(__DIR__ . '/Etc/modifiedHttpKernelv7.stub'), file_get_contents(app_path('Http/Kernel.php')));
        }
    }

    /** @test */
    public function migrate_fresh_command_works()
    {
        $this->assertFalse(Schema::hasTable('users'));
        Artisan::call('tenants:migrate-fresh');
        $this->assertFalse(Schema::hasTable('users'));
        tenancy()->init('test.localhost');
        $this->assertTrue(Schema::hasTable('users'));

        $this->assertFalse(DB::table('users')->exists());
        DB::table('users')->insert(['name' => 'xxx', 'password' => bcrypt('password'), 'email' => 'foo@bar.xxx']);
        $this->assertTrue(DB::table('users')->exists());

        // test that db is wiped
        Artisan::call('tenants:migrate-fresh');
        $this->assertFalse(DB::table('users')->exists());
    }

    /** @test */
    public function create_command_works()
    {
        Artisan::call('tenants:create -d aaa.localhost -d bbb.localhost plan=free email=foo@test.local');
        $tenant = tenancy()->all()[1]; // a tenant is autocreated prior to this
        $data = $tenant->data;
        unset($data['id']);

        $this->assertSame(['plan' => 'free', 'email' => 'foo@test.local'], $data);
        $this->assertSame(['aaa.localhost', 'bbb.localhost'], $tenant->domains);
    }
}
