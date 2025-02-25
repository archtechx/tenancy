<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Tests\Etc\User;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Jobs\DeleteDomains;
use Illuminate\Support\Facades\Artisan;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\DeleteDatabase;
use Illuminate\Database\DatabaseManager;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Events\TenantDeleted;
use Stancl\Tenancy\Tests\Etc\TestSeeder;
use Stancl\Tenancy\Events\DeletingTenant;
use Stancl\Tenancy\Events\DatabaseMigrated;
use Stancl\Tenancy\Tests\Etc\ExampleSeeder;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use Stancl\Tenancy\Database\Exceptions\TenantDatabaseDoesNotExistException;
use function Stancl\Tenancy\Tests\pest;

beforeEach(function () {
    if (file_exists($schemaPath = 'tests/Etc/tenant-schema-test.dump')) {
        unlink($schemaPath);
    }

    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    config([
        'tenancy.bootstrappers' => [
            DatabaseTenancyBootstrapper::class,
        ],
        'tenancy.filesystem.suffix_base' => 'tenant-',
        'tenancy.filesystem.root_override.public' => '%storage_path%/app/public/',
        'tenancy.filesystem.url_override.public' => 'public-%tenant%'
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

test('migrate command only throws exceptions if skip-failing is not passed', function() {
    Tenant::create();

    $tenantWithoutDatabase = Tenant::create();
    $databaseToDrop = $tenantWithoutDatabase->run(fn() => DB::connection()->getDatabaseName());

    DB::statement("DROP DATABASE `$databaseToDrop`");

    Tenant::create();

    expect(fn() => pest()->artisan('tenants:migrate --schema-path="tests/Etc/tenant-schema.dump"'))->toThrow(TenantDatabaseDoesNotExistException::class);
    expect(fn() => pest()->artisan('tenants:migrate --schema-path="tests/Etc/tenant-schema.dump" --skip-failing'))->not()->toThrow(TenantDatabaseDoesNotExistException::class);
});

test('migrate command does not stop after the first failure if skip-failing is passed', function() {
    $tenants = collect([
        Tenant::create(),
        $tenantWithoutDatabase = Tenant::create(),
        Tenant::create(),
    ]);

    $migratedTenants = 0;

    Event::listen(DatabaseMigrated::class, function() use (&$migratedTenants) {
        $migratedTenants++;
    });

    $databaseToDrop = $tenantWithoutDatabase->run(fn() => DB::connection()->getDatabaseName());

    DB::statement("DROP DATABASE `$databaseToDrop`");

    Artisan::call('tenants:migrate', [
        '--schema-path' => '"tests/Etc/tenant-schema.dump"',
        '--skip-failing' => true,
        '--tenants' => $tenants->pluck('id')->toArray(),
    ]);

    expect($migratedTenants)->toBe(2);
});

test('the tenants migrate command uses the schema dump correctly', function (bool $schemaPathAsConfig) {
    $artisanCommand = 'tenants:migrate';

    if ($schemaPathAsConfig) {
        // The schema dump path can be configured in 'tenancy.migration_parameters.--schema-path'
        // The tenants:migrate command will use the schema dump located at that path by default
        config(['tenancy.migration_parameters.--schema-path' => 'tests/Etc/tenant-schema.dump']);
    } else {
        // The schema dump path can be passed as an option to the tenants:migrate command
        $artisanCommand .= ' --schema-path="tests/Etc/tenant-schema.dump"';
    }

    $tenant = Tenant::create();

    Artisan::call($artisanCommand);

    // 'example' is a table included in the tests/Etc/tenant-schema dump
    // 'users' is a table created by the migrations
    // The tables weren't created in the central database
    expect(Schema::hasTable('example'))->toBeFalse();
    expect(Schema::hasTable('users'))->toBeFalse();

    tenancy()->initialize($tenant);

    // Both the table from the schema dump and the table from actual migrations
    // Were created in the tenant database
    expect(Schema::hasTable('example'))->toBeTrue();
    expect(Schema::hasTable('users'))->toBeTrue();
})->with([true, false]);

test('dump command works', function () {
    $tenant = Tenant::create();
    $schemaPath = 'tests/Etc/tenant-schema-test.dump';

    Artisan::call('tenants:migrate');

    expect($schemaPath)->not()->toBeFile();

    Artisan::call('tenants:dump ' . "--tenant='$tenant->id' --path='$schemaPath'");

    expect($schemaPath)->toBeFile();
});

test('dump command generates dump at the passed path', function() {
    $tenant = Tenant::create();

    Artisan::call('tenants:migrate');

    expect($schemaPath = 'tests/Etc/tenant-schema-test.dump')->not()->toBeFile();

    Artisan::call("tenants:dump --tenant='$tenant->id' --path='$schemaPath'");

    expect($schemaPath)->toBeFile();
});

test('dump command generates dump at the path specified in the tenancy migration parameters config', function() {
    config(['tenancy.migration_parameters.--schema-path' => $schemaPath = 'tests/Etc/tenant-schema-test.dump']);

    $tenant = Tenant::create();

    Artisan::call('tenants:migrate');

    expect($schemaPath)->not()->toBeFile();

    Artisan::call("tenants:dump --tenant='$tenant->id'");

    expect($schemaPath)->toBeFile();
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

test('seed command works', function () {
    $tenant = Tenant::create();
    Artisan::call('tenants:migrate');

    $tenant->run(function () {
        expect(DB::table('users')->count())->toBe(0);
    });

    Artisan::call('tenants:seed', ['--class' => TestSeeder::class]);

    $tenant->run(function () {
        $user = DB::table('users');
        expect($user->count())->toBe(1)
            ->and($user->first()->email)->toBe('seeded@user');
    });
});

test('database connection is switched to default after running commands', function (bool $initializeTenancy) {
    $tenant = Tenant::create();

    if ($initializeTenancy) {
        tenancy()->initialize($tenant);
    }

    $originalDBName = DB::connection()->getDatabaseName();

    Artisan::call('tenants:migrate');
    expect(DB::connection()->getDatabaseName())->toBe($originalDBName);

    Artisan::call('tenants:seed', ['--class' => ExampleSeeder::class]);
    expect(DB::connection()->getDatabaseName())->toBe($originalDBName);

    Artisan::call('tenants:rollback');
    expect(DB::connection()->getDatabaseName())->toBe($originalDBName);

    Artisan::call('tenants:migrate', ['--tenants' => [$tenant->getTenantKey()]]);

    pest()->artisan("tenants:run --tenants={$tenant->getTenantKey()} 'foo foo --b=bar --c=xyz'");

    expect(DB::connection()->getDatabaseName())->toBe($originalDBName);
})->with([
    'tenancy initialized' => true,
    'tenancy not initialized' => false,
]);

test('run command works', function () {
    $id = Tenant::create()->getTenantKey();

    Artisan::call('tenants:migrate', ['--tenants' => [$id]]);

    pest()->artisan("tenants:run --tenants=$id 'foo foo --b=bar --c=xyz'")
        ->expectsOutput("User's name is Test user")
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

    pest()->artisan('tenancy:install')
        ->expectsConfirmation('Would you like to show your support by starring the project on GitHub?', 'no')
        ->assertExitCode(0);
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
    $tenantId3 = Tenant::create()->getTenantKey();
    Artisan::call('tenants:migrate-fresh');

    pest()->artisan("tenants:run --tenants=$tenantId1 --tenants=$tenantId2 'foo foo --b=bar --c=xyz'")
        ->expectsOutputToContain('Tenant: ' . $tenantId1)
        ->expectsOutputToContain('Tenant: ' . $tenantId2)
        ->doesntExpectOutput('Tenant: ' . $tenantId3)
        ->assertExitCode(0);
});

test('link command works', function() {
    config(['tenancy.bootstrappers' => [FilesystemTenancyBootstrapper::class]]);

    $tenantId1 = Tenant::create()->getTenantKey();
    $tenantId2 = Tenant::create()->getTenantKey();
    pest()->artisan('tenants:link')
        ->assertExitCode(0);

    $this->assertDirectoryExists(storage_path("tenant-$tenantId1/app/public"));
    $this->assertEquals(storage_path("tenant-$tenantId1/app/public/"), readlink(public_path("public-$tenantId1")));

    $this->assertDirectoryExists(storage_path("tenant-$tenantId2/app/public"));
    $this->assertEquals(storage_path("tenant-$tenantId2/app/public/"), readlink(public_path("public-$tenantId2")));

    pest()->artisan('tenants:link', [
        '--remove' => true,
    ])->assertExitCode(0);

    $this->assertDirectoryDoesNotExist(public_path("public-$tenantId1"));
    $this->assertDirectoryDoesNotExist(public_path("public-$tenantId2"));
});

test('link command works with a specified tenant', function() {
    config(['tenancy.bootstrappers' => [FilesystemTenancyBootstrapper::class]]);

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
        ->expectsOutputToContain("Tenant: $id.")
        ->expectsOutput("User created: Abrar(email@localhost)")
        ->assertExitCode(0);

    // Assert we are in central context
    expect(tenancy()->initialized)->toBeFalse();

    // Assert user was created in tenant context
    tenancy()->initialize($tenant);
    $user = User::first();

    // Assert user is same as provided using the command
    expect($user->name)->toBe('Abrar');
    expect($user->email)->toBe('email@localhost');
});

test('run command accepts arguments and options correctly', function() {
    $tenant = Tenant::create();
    $id = $tenant->getTenantKey();

    // Use unquoted single-word arguments and quoted arguments with spaces
    pest()->artisan("tenants:run \"bar username 'email@localhost' adsfg123 'some Arg' --option='some option'\" --tenants=$id")
        ->expectsOutputToContain("Tenant: $id.")
        ->expectsOutput("Name: username")
        ->expectsOutput("Email: email@localhost")
        ->expectsOutput("Password: adsfg123")
        ->expectsOutput("Argument: some Arg")
        ->expectsOutput("Option: some option")
        ->assertExitCode(0);
});

test('migrate fresh command only deletes tenant databases if drop_tenant_databases_on_migrate_fresh is true', function (bool $dropTenantDBsOnMigrateFresh) {
    Event::listen(DeletingTenant::class,
        JobPipeline::make([DeleteDomains::class])->send(function (DeletingTenant $event) {
            return $event->tenant;
        })->shouldBeQueued(false)->toListener()
    );

    Event::listen(
        TenantDeleted::class,
        JobPipeline::make([DeleteDatabase::class])->send(function (TenantDeleted $event) {
            return $event->tenant;
        })->shouldBeQueued(false)->toListener()
    );

    config(['tenancy.database.drop_tenant_databases_on_migrate_fresh' => $dropTenantDBsOnMigrateFresh]);
    $shouldHaveDBAfterMigrateFresh = ! $dropTenantDBsOnMigrateFresh;

    /** @var Tenant[] $tenants */
    $tenants = [
        Tenant::create(),
        Tenant::create(),
        Tenant::create(),
    ];

    $tenantHasDatabase = fn (Tenant $tenant) => $tenant->database()->manager()->databaseExists($tenant->database()->getName());

    foreach ($tenants as $tenant) {
        expect($tenantHasDatabase($tenant))->toBeTrue();
    }

    pest()->artisan('migrate:fresh', [
        '--force' => true,
        '--path' => __DIR__ . '/../assets/migrations',
        '--realpath' => true,
    ]);

    foreach ($tenants as $tenant) {
        expect($tenantHasDatabase($tenant))->toBe($shouldHaveDBAfterMigrateFresh);
    }
})->with([true, false]);
