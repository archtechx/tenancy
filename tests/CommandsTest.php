<?php

declare(strict_types=1);

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

uses(Stancl\Tenancy\Tests\TestCase::class);

beforeEach(function () {
    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    config(['tenancy.bootstrappers' => [
        DatabaseTenancyBootstrapper::class,
    ]]);

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
});

afterEach(function () {
    // Cleanup tenancy config cache
    if (file_exists(base_path('config/tenancy.php'))) {
        unlink(base_path('config/tenancy.php'));
    }
});

test('migrate command doesnt change the db connection', function () {
    $this->assertFalse(Schema::hasTable('users'));

    $old_connection_name = app(\Illuminate\Database\DatabaseManager::class)->connection()->getName();
    Artisan::call('tenants:migrate');
    $new_connection_name = app(\Illuminate\Database\DatabaseManager::class)->connection()->getName();

    $this->assertFalse(Schema::hasTable('users'));
    $this->assertEquals($old_connection_name, $new_connection_name);
    $this->assertNotEquals('tenant', $new_connection_name);
});

test('migrate command works without options', function () {
    $tenant = Tenant::create();

    $this->assertFalse(Schema::hasTable('users'));
    Artisan::call('tenants:migrate');
    $this->assertFalse(Schema::hasTable('users'));

    tenancy()->initialize($tenant);

    $this->assertTrue(Schema::hasTable('users'));
});

test('migrate command works with tenants option', function () {
    $tenant = Tenant::create();
    Artisan::call('tenants:migrate', [
        '--tenants' => [$tenant['id']],
    ]);

    $this->assertFalse(Schema::hasTable('users'));
    tenancy()->initialize(Tenant::create());
    $this->assertFalse(Schema::hasTable('users'));

    tenancy()->initialize($tenant);
    $this->assertTrue(Schema::hasTable('users'));
});

test('migrate command loads schema state', function () {
    $tenant = Tenant::create();

    $this->assertFalse(Schema::hasTable('schema_users'));
    $this->assertFalse(Schema::hasTable('users'));

    Artisan::call('tenants:migrate --schema-path="tests/Etc/tenant-schema.dump"');

    $this->assertFalse(Schema::hasTable('schema_users'));
    $this->assertFalse(Schema::hasTable('users'));

    tenancy()->initialize($tenant);

    // Check for both tables to see if missing migrations also get executed
    $this->assertTrue(Schema::hasTable('schema_users'));
    $this->assertTrue(Schema::hasTable('users'));
});

test('dump command works', function () {
    $tenant = Tenant::create();
    Artisan::call('tenants:migrate');

    tenancy()->initialize($tenant);

    Artisan::call('tenants:dump --path="tests/Etc/tenant-schema-test.dump"');
    $this->assertFileExists('tests/Etc/tenant-schema-test.dump');
});

test('rollback command works', function () {
    $tenant = Tenant::create();
    Artisan::call('tenants:migrate');
    $this->assertFalse(Schema::hasTable('users'));

    tenancy()->initialize($tenant);

    $this->assertTrue(Schema::hasTable('users'));
    Artisan::call('tenants:rollback');
    $this->assertFalse(Schema::hasTable('users'));
});

test('seed command works', function () {
    $this->markTestIncomplete();
});

test('database connection is switched to default', function () {
    $originalDBName = DB::connection()->getDatabaseName();

    Artisan::call('tenants:migrate');
    $this->assertSame($originalDBName, DB::connection()->getDatabaseName());

    Artisan::call('tenants:seed', ['--class' => ExampleSeeder::class]);
    $this->assertSame($originalDBName, DB::connection()->getDatabaseName());

    Artisan::call('tenants:rollback');
    $this->assertSame($originalDBName, DB::connection()->getDatabaseName());

    $this->run_commands_works();
    $this->assertSame($originalDBName, DB::connection()->getDatabaseName());
});

test('database connection is switched to default when tenancy has been initialized', function () {
    tenancy()->initialize(Tenant::create());

    $this->database_connection_is_switched_to_default();
});

test('run commands works', function () {
    $id = Tenant::create()->getTenantKey();

    Artisan::call('tenants:migrate', ['--tenants' => [$id]]);

    $this->artisan("tenants:run foo --tenants=$id --argument='a=foo' --option='b=bar' --option='c=xyz'")
        ->expectsOutput("User's name is Test command")
        ->expectsOutput('foo')
        ->expectsOutput('xyz');
});

test('install command works', function () {
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
});

test('migrate fresh command works', function () {
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
});

test('run command with array of tenants works', function () {
    $tenantId1 = Tenant::create()->getTenantKey();
    $tenantId2 = Tenant::create()->getTenantKey();
    Artisan::call('tenants:migrate-fresh');

    $this->artisan("tenants:run foo --tenants=$tenantId1 --tenants=$tenantId2 --argument='a=foo' --option='b=bar' --option='c=xyz'")
        ->expectsOutput('Tenant: ' . $tenantId1)
        ->expectsOutput('Tenant: ' . $tenantId2);
});
