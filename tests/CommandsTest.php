<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
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
use Stancl\Tenancy\Tests\Etc\TestSeeder;
use Stancl\Tenancy\Tests\Etc\User;

beforeEach(function () {
    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    config(['tenancy.bootstrappers' => [
        DatabaseTenancyBootstrapper::class,
    ]]);

    config([
        'tenancy.bootstrappers' => [
            DatabaseTenancyBootstrapper::class,
        ],
        'tenancy.filesystem.suffix_base' => 'tenant-',
        'tenancy.filesystem.root_override.public' => '%storage_path%/app/public/',
        'tenancy.filesystem.url_override.public' => 'public-%tenant_id%'
    ]);

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
    expect(Schema::hasTable('users'))->toBeFalse();

    $old_connection_name = app(DatabaseManager::class)->connection()->getName();
    Artisan::call('tenants:migrate');
    $new_connection_name = app(DatabaseManager::class)->connection()->getName();

    expect(Schema::hasTable('users'))->toBeFalse();
    expect($new_connection_name)->toEqual($old_connection_name);
    pest()->assertNotEquals('tenant', $new_connection_name);
});

test('migrate command works without options', function () {
    $tenant = Tenant::create();

    expect(Schema::hasTable('users'))->toBeFalse();
    Artisan::call('tenants:migrate');
    expect(Schema::hasTable('users'))->toBeFalse();

    tenancy()->initialize($tenant);

    expect(Schema::hasTable('users'))->toBeTrue();
});

test('migrate command works with tenants option', function () {
    $tenant = Tenant::create();
    Artisan::call('tenants:migrate', [
        '--tenants' => [$tenant['id']],
    ]);

    expect(Schema::hasTable('users'))->toBeFalse();
    tenancy()->initialize(Tenant::create());
    expect(Schema::hasTable('users'))->toBeFalse();

    tenancy()->initialize($tenant);
    expect(Schema::hasTable('users'))->toBeTrue();
});

test('migrate command loads schema state', function () {
    $tenant = Tenant::create();

    expect(Schema::hasTable('schema_users'))->toBeFalse();
    expect(Schema::hasTable('users'))->toBeFalse();

    Artisan::call('tenants:migrate --schema-path="tests/Etc/tenant-schema.dump"');

    expect(Schema::hasTable('schema_users'))->toBeFalse();
    expect(Schema::hasTable('users'))->toBeFalse();

    tenancy()->initialize($tenant);

    // Check for both tables to see if missing migrations also get executed
    expect(Schema::hasTable('schema_users'))->toBeTrue();
    expect(Schema::hasTable('users'))->toBeTrue();
});

test('dump command works', function () {
    $tenant = Tenant::create();
    Artisan::call('tenants:migrate');

    tenancy()->initialize($tenant);

    Artisan::call('tenants:dump --path="tests/Etc/tenant-schema-test.dump"');
    expect('tests/Etc/tenant-schema-test.dump')->toBeFile();
});

test('rollback command works', function () {
    $tenant = Tenant::create();
    Artisan::call('tenants:migrate');
    expect(Schema::hasTable('users'))->toBeFalse();

    tenancy()->initialize($tenant);

    expect(Schema::hasTable('users'))->toBeTrue();
    Artisan::call('tenants:rollback');
    expect(Schema::hasTable('users'))->toBeFalse();
});

test('seed command works', function (){
    $tenant = Tenant::create();
    Artisan::call('tenants:migrate');

    $tenant->run(function (){
        expect(DB::table('users')->count())->toBe(0);
    });

    Artisan::call('tenants:seed', ['--class' => TestSeeder::class]);

    $tenant->run(function (){
        $user = DB::table('users');
        expect($user->count())->toBe(1)
            ->and($user->first()->email)->toBe('seeded@user');
    });
});

test('database connection is switched to default', function () {
    databaseConnectionSwitchedToDefault();
});

test('database connection is switched to default when tenancy has been initialized', function () {
    tenancy()->initialize(Tenant::create());

    databaseConnectionSwitchedToDefault();
});

test('run command works', function () {
    runCommandWorks();
});

test('install command works', function () {
    if (! is_dir($dir = app_path('Http'))) {
        mkdir($dir, 0777, true);
    }
    if (! is_dir($dir = base_path('routes'))) {
        mkdir($dir, 0777, true);
    }

    pest()->artisan('tenancy:install');
    expect(base_path('routes/tenant.php'))->toBeFile();
    expect(base_path('config/tenancy.php'))->toBeFile();
    expect(app_path('Providers/TenancyServiceProvider.php'))->toBeFile();
    expect(database_path('migrations/2019_09_15_000010_create_tenants_table.php'))->toBeFile();
    expect(database_path('migrations/2019_09_15_000020_create_domains_table.php'))->toBeFile();
    expect(database_path('migrations/tenant'))->toBeDirectory();
});

test('migrate fresh command works', function () {
    $tenant = Tenant::create();

    expect(Schema::hasTable('users'))->toBeFalse();
    Artisan::call('tenants:migrate-fresh');
    expect(Schema::hasTable('users'))->toBeFalse();

    tenancy()->initialize($tenant);

    expect(Schema::hasTable('users'))->toBeTrue();

    expect(DB::table('users')->exists())->toBeFalse();
    DB::table('users')->insert(['name' => 'xxx', 'password' => bcrypt('password'), 'email' => 'foo@bar.xxx']);
    expect(DB::table('users')->exists())->toBeTrue();

    // test that db is wiped
    Artisan::call('tenants:migrate-fresh');
    expect(DB::table('users')->exists())->toBeFalse();
});

test('run command with array of tenants works', function () {
    $tenantId1 = Tenant::create()->getTenantKey();
    $tenantId2 = Tenant::create()->getTenantKey();
    Artisan::call('tenants:migrate-fresh');

    pest()->artisan("tenants:run --tenants=$tenantId1 --tenants=$tenantId2 'foo foo --b=bar --c=xyz'")
        ->expectsOutputToContain('Tenant: ' . $tenantId1)
        ->expectsOutputToContain('Tenant: ' . $tenantId2)
        ->assertExitCode(0);
});

test('link command works', function() {
    $tenantId1 = Tenant::create()->getTenantKey();
    $tenantId2 = Tenant::create()->getTenantKey();
    pest()->artisan('tenants:link');

    $this->assertDirectoryExists(storage_path("tenant-$tenantId1/app/public"));
    $this->assertEquals(storage_path("tenant-$tenantId1/app/public/"), readlink(public_path("public-$tenantId1")));

    $this->assertDirectoryExists(storage_path("tenant-$tenantId2/app/public"));
    $this->assertEquals(storage_path("tenant-$tenantId2/app/public/"), readlink(public_path("public-$tenantId2")));

    pest()->artisan('tenants:link', [
        '--remove' => true,
    ]);

    $this->assertDirectoryDoesNotExist(public_path("public-$tenantId1"));
    $this->assertDirectoryDoesNotExist(public_path("public-$tenantId2"));
});

test('link command works with a specified tenant', function() {
    $tenantKey = Tenant::create()->getTenantKey();

    pest()->artisan('tenants:link', [
        '--tenants' => [$tenantKey],
    ]);

    $this->assertDirectoryExists(storage_path("tenant-$tenantKey/app/public"));
    $this->assertEquals(storage_path("tenant-$tenantKey/app/public/"), readlink(public_path("public-$tenantKey")));

    pest()->artisan('tenants:link', [
        '--remove' => true,
        '--tenants' => [$tenantKey],
    ]);

    $this->assertDirectoryDoesNotExist(public_path("public-$tenantKey"));
});

test('run command works when sub command asks questions and accepts arguments', function () {
    $tenant = Tenant::create();
    $id = $tenant->getTenantKey();

    Artisan::call('tenants:migrate');

    pest()->artisan("tenants:run --tenants=$id 'user:addwithname Abrar' ")
        ->expectsQuestion('What is your email?', 'email@localhost')
        ->expectsOutput("Tenant: $id")
        ->expectsOutput("User created: Abrar(email@localhost)");

    // Assert we are in central context
    expect(tenancy()->initialized)->toBeFalse();

    // Assert user was created in tenant context
    tenancy()->initialize($tenant);
    $user = User::first();

    // Assert user is same as provided using the command
    expect($user->name)->toBe('Abrar');
    expect($user->email)->toBe('email@localhost');
});

test('migrate fresh command deletes tenant databases based on the tenancy.database.drop_tenant_databases_on_migrate_fresh config value', function() {
    config(['tenancy.database.drop_tenant_databases_on_migrate_fresh' => false]);

    /** @var Tenant[] $tenants */
    $tenants = [
        Tenant::create(),
        Tenant::create(),
        Tenant::create(),
    ];

    Artisan::call('migrate:fresh', [
        '--force' => true,
        '--path' => __DIR__ . '/../assets/migrations',
        '--realpath' => true,
    ]);

    $tenantDatabaseExists = fn(Tenant $tenant) => $tenant->database()->manager()->databaseExists($tenant->database()->getName());

    foreach ($tenants as $tenant) {
        expect($tenantDatabaseExists($tenant))->toBeTrue();
    }

    pest()->artisan('migrate:fresh', [
        '--force' => true,
        '--path' => __DIR__ . '/../assets/migrations',
        '--realpath' => true,
    ]);

    foreach ($tenants as $tenant) {
        expect($tenantDatabaseExists($tenant))->toBeTrue();
    }

    config(['tenancy.database.drop_tenant_databases_on_migrate_fresh' => true]);

    $tenants = [
        Tenant::create(),
        Tenant::create(),
        Tenant::create(),
    ];

    foreach ($tenants as $tenant) {
        expect($tenantDatabaseExists($tenant))->toBeTrue();
    }

    pest()->artisan('migrate:fresh', [
        '--force' => true,
        '--path' => __DIR__ . '/../assets/migrations',
        '--realpath' => true,
    ]);

    foreach ($tenants as $tenant) {
        expect($tenantDatabaseExists($tenant))->toBeFalse();
    }
})->group('fresh');

// todo@tests
function runCommandWorks(): void
{
    $id = Tenant::create()->getTenantKey();

    Artisan::call('tenants:migrate', ['--tenants' => [$id]]);

    pest()->artisan("tenants:run --tenants=$id 'foo foo --b=bar --c=xyz' ")
        ->expectsOutput("User's name is Test command")
        ->expectsOutput('foo')
        ->expectsOutput('xyz');
}

// todo@tests
function databaseConnectionSwitchedToDefault()
{
    $originalDBName = DB::connection()->getDatabaseName();

    Artisan::call('tenants:migrate');
    expect(DB::connection()->getDatabaseName())->toBe($originalDBName);

    Artisan::call('tenants:seed', ['--class' => ExampleSeeder::class]);
    expect(DB::connection()->getDatabaseName())->toBe($originalDBName);

    Artisan::call('tenants:rollback');
    expect(DB::connection()->getDatabaseName())->toBe($originalDBName);

    runCommandWorks();

    expect(DB::connection()->getDatabaseName())->toBe($originalDBName);
}
