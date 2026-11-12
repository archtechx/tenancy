<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
use Illuminate\Database\Console\WipeCommand;
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
use Stancl\Tenancy\Events\MigratingDatabase;
use Stancl\Tenancy\Commands\Migrate;
use Symfony\Component\Console\Exception\InvalidOptionException;

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

test('migrate command uses the passed database option as the template tenant connection', function () {
    $originalTemplateConnection = config('tenancy.database.template_tenant_connection');

    // Add a custom connection that will be used as the template for the tenant connection
    // Identical to the default (mysql), just with different charset and collation
    config(['database.connections.custom_connection' => [
        "driver" => "mysql",
        "url" => "",
        "host" => "mysql",
        "port" => "3306",
        "database" => "main",
        "username" => "root",
        "password" => "password",
        "unix_socket" => "",
        "charset" => "latin1", // Different from the default (utf8mb4)
        "collation" => "latin1_swedish_ci", // Different from the default (utf8mb4_unicode_ci)
        "prefix" => "",
        "prefix_indexes" => true,
        "strict" => true,
        "engine" => null,
        "options" => []
    ]]);

    $templateConnectionDuringMigration = null;
    $tenantConnectionDuringMigration = null;

    Event::listen(MigratingDatabase::class, function() use (&$templateConnectionDuringMigration, &$tenantConnectionDuringMigration) {
        $templateConnectionDuringMigration = config('tenancy.database.template_tenant_connection');
        $tenantConnectionDuringMigration = DB::connection('tenant')->getConfig();
    });

    // The original tenant template connection config remains default
    expect(config('tenancy.database.template_tenant_connection'))->toBe($originalTemplateConnection);

    Tenant::create();

    // The original template connection is used when the --database option is not passed
    pest()->artisan('tenants:migrate');
    expect($templateConnectionDuringMigration)->toBe($originalTemplateConnection);

    Tenant::create();

    // The migrate command temporarily uses the connection passed in the --database option
    pest()->artisan('tenants:migrate', ['--database' => 'custom_connection']);
    expect($templateConnectionDuringMigration)->toBe('custom_connection');

    // The tenant connection during migration actually used custom_connection's config
    expect($tenantConnectionDuringMigration['charset'])->toBe('latin1');
    expect($tenantConnectionDuringMigration['collation'])->toBe('latin1_swedish_ci');

    // The tenant template connection config is restored to the original after migrating
    expect(config('tenancy.database.template_tenant_connection'))->toBe($originalTemplateConnection);
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

test('migrate fresh command only shows migration output when run with the verbose option', function () {
    $tenant = Tenant::create();
    $migratingOutput = 'Migrating tenant ' . $tenant->getTenantKey();

    // CI runs pest with --verbose which makes Artisan::call() inherit the verbosity
    // so we cannot easily test commands without -v. To work around that, we temporarily
    // override $_ENV['SHELL_VERBOSITY'] immediately before executing the command. If this
    // ever stops working, try also overriding the value in $_SERVER and putenv().
    $emptySentinel = new \stdClass();
    $originalVerbosity = $_ENV['SHELL_VERBOSITY'] ?? $emptySentinel;
    try {
        $_ENV['SHELL_VERBOSITY'] = 0;
        Artisan::call('tenants:migrate-fresh');
        $defaultOutput = Artisan::output();
    } finally {
        if ($originalVerbosity === $emptySentinel) {
            unset($_ENV['SHELL_VERBOSITY']);
        } else {
            $_ENV['SHELL_VERBOSITY'] = $originalVerbosity;
        }
    }

    Artisan::call('tenants:migrate-fresh -v');
    $verboseOutput = Artisan::output();

    // The output is silent by default and only shown with the verbose option
    expect($defaultOutput)->not()->toContain($migratingOutput);
    expect($verboseOutput)->toContain($migratingOutput);
});

test('migrate fresh command works with multiple processes', function () {
    $tenants = [Tenant::create(), Tenant::create(), Tenant::create()];

    expect(Artisan::call('tenants:migrate-fresh', ['--processes' => 3]))->toBe(0);

    foreach ($tenants as $tenant) {
        expect($tenant->run(fn () => Schema::hasTable('users')))->toBeTrue();
    }
});

test('migrate fresh command with multiple processes only continues after a failure if skip-failing is passed', function (bool $skipFailing) {
    // Chunks are made from sorted tenant keys, so with two processes,
    // the first two tenants are migrated by the same child process
    $prefix = Str::lower(Str::random(8));
    $tenants = [Tenant::create(['id' => "$prefix-a"]), Tenant::create(['id' => "$prefix-b"]), Tenant::create(['id' => "$prefix-c"])];

    $databaseToDrop = $tenants[0]->run(fn () => DB::connection()->getDatabaseName());
    DB::statement("DROP DATABASE `$databaseToDrop`");

    // If an exception propagated out of a child process, the child would continue running the test
    // suite (including this test's teardown) instead of exiting, which would create the marker file
    //
    // This tests for a regression where an exception thrown in a forked child didn't make it exit.
    // Instead, the exception went up the call stack the child inherited from the parent, which in
    // the case of Artisan::call() in a test means back into this test closure. The child's copy of
    // this test then fails, its teardown runs (creating the marker file below), and the child goes
    // on running any remaining tests. The parent waits for all children to exit before
    // Artisan::call() returns, so the marker exists by the time it's checked below.
    $parentPid = getmypid();
    $escapedChildMarker = sys_get_temp_dir() . '/tenancy-escaped-child-' . $parentPid;
    $this->beforeApplicationDestroyed(function () use ($parentPid, $escapedChildMarker) {
        if (getmypid() !== $parentPid) {
            touch($escapedChildMarker);
        }
    });

    try {
        expect(Artisan::call('tenants:migrate-fresh', array_filter([
            '--processes' => 2,
            '--skip-failing' => $skipFailing,
        ])))->toBe(1);

        expect(file_exists($escapedChildMarker))->toBeFalse();
    } finally {
        @unlink($escapedChildMarker);
    }

    // The output distinguishes a child that skipped a failed tenant from a child that stopped
    $output = Artisan::output();
    $notProcessedMessage = 'Some tenants were not processed';

    if ($skipFailing) {
        expect($output)->toContain('Child process [0]')
            ->and($output)->toContain('completed with failures')
            ->and($output)->not()->toContain('stopped after a failure')
            ->and($output)->not()->toContain($notProcessedMessage);
    } else {
        expect($output)->toContain('stopped after a failure')
            ->and($output)->not()->toContain('completed with failures')
            ->and($output)->toContain($notProcessedMessage);
    }

    expect($tenants[1]->run(fn () => Schema::hasTable('users')))->toBe($skipFailing);
    expect($tenants[2]->run(fn () => Schema::hasTable('users')))->toBeTrue();
})->with([true, false]);

test('migrate fresh command only continues after a failure if skip-failing is passed', function () {
    $tenants = [Tenant::create(), $tenantWithoutDatabase = Tenant::create(), Tenant::create()];

    $databaseToDrop = $tenantWithoutDatabase->run(fn () => DB::connection()->getDatabaseName());
    DB::statement("DROP DATABASE `$databaseToDrop`");

    expect(fn () => Artisan::call('tenants:migrate-fresh'))->toThrow(TenantDatabaseDoesNotExistException::class);
    expect(Artisan::call('tenants:migrate-fresh', ['--skip-failing' => true]))->toBe(1);

    expect($tenants[0]->run(fn () => Schema::hasTable('users')))->toBeTrue();
    expect($tenants[2]->run(fn () => Schema::hasTable('users')))->toBeTrue();
});

test('migrate fresh command skips migrating tenants whose database could not be wiped', function (int $processes) {
    // The tenants' databases are created, but not migrated
    $tenants = [Tenant::create(), Tenant::create()];

    // Make db:wipe fail for every tenant. A prohibited db:wipe (this is also what
    // DB::prohibitDestructiveCommands() does) returns a non-zero exit code instead of throwing.
    WipeCommand::prohibit();

    try {
        expect(Artisan::call('tenants:migrate-fresh', ['--processes' => $processes]))->toBe(1);
    } finally {
        WipeCommand::prohibit(false);
    }

    // The migrations were skipped for every tenant
    foreach ($tenants as $tenant) {
        expect($tenant->run(fn () => Schema::hasTable('users')))->toBeFalse();
    }

    // Output of child processes isn't captured, so the output is only checked when running sequentially
    if ($processes === 1) {
        // Each tenant shows "Dropping tables ... FAIL" and "Migrating ... SKIPPED", which also
        // means that wiping was attempted for every tenant, not just the first one
        $output = Artisan::output();
        expect(substr_count($output, 'FAIL'))->toBe(count($tenants));
        expect(substr_count($output, 'SKIPPED'))->toBe(count($tenants));
    }
})->with([
    'sequentially' => 1,
    'with multiple processes' => 2,
]);

test('parallel commands throw an exception instead of exiting when the process count is invalid', function (string $command, int $processes) {
    // Previously, the command called exit(1), which ended the whole test run
    expect(fn () => Artisan::call($command, ['--processes' => $processes]))->toThrow(InvalidOptionException::class);
})->with([
    'tenants:migrate',
    'tenants:rollback',
    'tenants:migrate-fresh',
])->with([
    'less than 1' => 0,
    'more than the maximum' => Migrate::MAX_PROCESSES + 1,
]);

test('migrate command restores the template tenant connection when it throws an exception', function () {
    $originalTemplateConnection = config('tenancy.database.template_tenant_connection');

    expect(fn () => Artisan::call('tenants:migrate', ['--database' => 'central', '--processes' => 0]))->toThrow(InvalidOptionException::class);

    expect(config('tenancy.database.template_tenant_connection'))->toBe($originalTemplateConnection);
});

test('parallel commands process the remaining chunks when forking a child process fails', function () {
    // Chunks are made from sorted tenant keys, so with three processes, each child migrates one tenant
    $prefix = Str::lower(Str::random(8));
    $tenants = [Tenant::create(['id' => "$prefix-a"]), Tenant::create(['id' => "$prefix-b"]), Tenant::create(['id' => "$prefix-c"])];

    $command = new class(app('migrator'), app('events')) extends Migrate {
        protected int $forks = 0;

        protected function forkProcess(mixed ...$args): int
        {
            // Fail to fork the second child process
            return $this->forks++ === 1 ? -1 : parent::forkProcess(...$args);
        }
    };

    // Replaces the tenants:migrate command
    Artisan::registerCommand($command);

    expect(Artisan::call('tenants:migrate', ['--processes' => 3]))->toBe(1);

    // Only the children that were forked are reported, with the index of their chunk
    expect($output = Artisan::output())->toContain('Unable to fork process (iteration 1)!')
        ->and($output)->toMatch('/Child process \[0\] \(PID \d+\) finished successfully/')
        ->and($output)->not()->toContain('Child process [1]')
        ->and($output)->toMatch('/Child process \[2\] \(PID \d+\) finished successfully/')
        ->and($output)->toContain('Some tenants were not processed');

    expect($tenants[0]->run(fn () => Schema::hasTable('users')))->toBeTrue();
    expect($tenants[1]->run(fn () => Schema::hasTable('users')))->toBeFalse();
    expect($tenants[2]->run(fn () => Schema::hasTable('users')))->toBeTrue();
});

test('migrate fresh command respects force option in production', function () {
    // Set environment to production
    app()->detectEnvironment(fn() => 'production');

    Tenant::create();

    // Without --force in production, command should prompt for confirmation
    pest()->artisan('tenants:migrate-fresh')
        ->expectsConfirmation('Are you sure you want to run this command?');

    // With --force, command should succeed without prompting
    pest()->artisan('tenants:migrate-fresh', ['--force' => true])
        ->assertSuccessful();
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

test('run command returns the exit code of the failed command', function () {
    $tenantId1 = Tenant::create()->getTenantKey();
    $tenantId2 = Tenant::create()->getTenantKey();

    pest()->artisan("tenants:run --tenants=$tenantId1 --tenants=$tenantId2 'exit-code 0'")
        ->assertExitCode(0);

    // The command still runs for the remaining tenants after it fails for one
    pest()->artisan("tenants:run --tenants=$tenantId1 --tenants=$tenantId2 'exit-code 3'")
        ->expectsOutputToContain("Exiting with code 3 for tenant $tenantId1")
        ->expectsOutputToContain("Exiting with code 3 for tenant $tenantId2")
        ->assertExitCode(3);
});

test('run command writes the output of the command to its own output', function () {
    $tenantId = Tenant::create()->getTenantKey();

    expect(Artisan::call("tenants:run --tenants=$tenantId 'exit-code 0'"))->toBe(0);

    expect(Artisan::output())
        ->toContain("Tenant: $tenantId")
        ->toContain("Exiting with code 0 for tenant $tenantId");
});

test('output options passed to the command run by the run command only apply to that command', function () {
    $tenantId1 = Tenant::create()->getTenantKey();
    $tenantId2 = Tenant::create()->getTenantKey();

    Artisan::call("tenants:run --tenants=$tenantId1 --tenants=$tenantId2 'exit-code 0 -q'");

    expect(Artisan::output())
        ->toContain("Tenant: $tenantId1")
        ->toContain("Tenant: $tenantId2")
        ->not->toContain('Exiting with code 0');

    Artisan::call("tenants:run --tenants=$tenantId1 --tenants=$tenantId2 'exit-code 0 --ansi'");

    // The output passed by Artisan::call() isn't decorated, so it shouldn't contain any ANSI escape codes
    expect(Artisan::output())
        ->toContain("Tenant: $tenantId2")
        ->not->toContain("\e[");
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

test('migrate commands can skip specified tenants', function (string $command) {
    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();
    $tenant3 = Tenant::create();

    pest()->artisan("{$command} --skip-tenants={$tenant1->getTenantKey()} --skip-tenants={$tenant2->getTenantKey()}");

    tenancy()->initialize($tenant1);

    expect(Schema::hasTable('users'))->toBeFalse();

    tenancy()->initialize($tenant2);

    expect(Schema::hasTable('users'))->toBeFalse();

    tenancy()->initialize($tenant3);

    expect(Schema::hasTable('users'))->toBeTrue();
})->with([
    'tenants:migrate',
    'tenants:migrate-fresh',
]);

test('run command can skip specified tenants', function () {
    $tenant1 = Tenant::create()->getTenantKey();
    $tenant2 = Tenant::create()->getTenantKey();
    $tenant3 = Tenant::create()->getTenantKey();

    pest()->artisan("tenants:run --skip-tenants=$tenant1 --skip-tenants=$tenant2 'bar foo foo@bar foobar arg --option=option'")
        ->doesntExpectOutputToContain("Tenant: $tenant1")
        ->doesntExpectOutputToContain("Tenant: $tenant2")
        ->expectsOutputToContain("Tenant: $tenant3")
        ->assertExitCode(0);
});

test('tenants and skip-tenants options can be used together', function () {
    $tenant1 = Tenant::create()->getTenantKey();
    $tenant2 = Tenant::create()->getTenantKey();
    $tenant3 = Tenant::create()->getTenantKey();

    // Scope to tenant1+tenant2, then skip tenant2 — only tenant1 should run
    pest()->artisan("tenants:run --tenants=$tenant1 --tenants=$tenant2 --skip-tenants=$tenant2 'bar foo foo@bar foobar arg --option=option'")
        ->expectsOutputToContain("Tenant: $tenant1")
        ->doesntExpectOutputToContain("Tenant: $tenant2")
        ->doesntExpectOutputToContain("Tenant: $tenant3")
        ->assertExitCode(0);
});
