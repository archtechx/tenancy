<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Contracts\ManagesDatabaseUsers;
use Stancl\Tenancy\Events\DatabaseCreated;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Database\Exceptions\TenantDatabaseUserAlreadyExistsException;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Database\TenantDatabaseManagers\MySQLDatabaseManager;
use Stancl\Tenancy\Database\TenantDatabaseManagers\PermissionControlledMySQLDatabaseManager;
use Stancl\Tenancy\Tests\Etc\Tenant;

beforeEach(function () {
    config([
        'tenancy.database.managers.mysql' => PermissionControlledMySQLDatabaseManager::class,
        'tenancy.database.suffix' => '',
        'tenancy.database.template_tenant_connection' => 'mysql',
    ]);

    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());
});

test('users are created when permission controlled mysql manager is used', function () {
    $tenant = new Tenant([
        'id' => 'foo' . Str::random(10),
    ]);
    $tenant->database()->makeCredentials();

    /** @var ManagesDatabaseUsers $manager */
    $manager = $tenant->database()->manager();
    expect($manager->userExists($tenant->database()->getUsername()))->toBeFalse();

    $tenant->save();

    expect($manager->userExists($tenant->database()->getUsername()))->toBeTrue();
});

test('a tenants database cannot be created when the user already exists', function () {
    $username = 'foo' . Str::random(8);
    $tenant = Tenant::create([
        'tenancy_db_username' => $username,
    ]);

    /** @var ManagesDatabaseUsers $manager */
    $manager = $tenant->database()->manager();
    expect($manager->userExists($tenant->database()->getUsername()))->toBeTrue();
    expect($manager->databaseExists($tenant->database()->getName()))->toBeTrue();

    pest()->expectException(TenantDatabaseUserAlreadyExistsException::class);
    Event::fake([DatabaseCreated::class]);

    $tenant2 = Tenant::create([
        'tenancy_db_username' => $username,
    ]);

    /** @var ManagesDatabaseUsers $manager */
    $manager2 = $tenant2->database()->manager();

    // database was not created because of DB transaction
    expect($manager2->databaseExists($tenant2->database()->getName()))->toBeFalse();
    Event::assertNotDispatched(DatabaseCreated::class);
});

test('correct grants are given to users', function () {
    PermissionControlledMySQLDatabaseManager::$grants = [
        'ALTER', 'ALTER ROUTINE', 'CREATE',
    ];

    $tenant = Tenant::create([
        'tenancy_db_username' => $user = 'user' . Str::random(8),
    ]);

    $query = DB::connection('mysql')->select("SHOW GRANTS FOR `{$tenant->database()->getUsername()}`@`%`")[1];
    expect($query->{"Grants for {$user}@%"})->toStartWith('GRANT CREATE, ALTER, ALTER ROUTINE ON'); // @mysql because that's the hostname within the docker network
});

test('having existing databases without users and switching to permission controlled mysql manager doesnt break existing dbs', function () {
    config([
        'tenancy.database.managers.mysql' => MySQLDatabaseManager::class,
        'tenancy.database.suffix' => '',
        'tenancy.database.template_tenant_connection' => 'mysql',
        'tenancy.bootstrappers' => [
            DatabaseTenancyBootstrapper::class,
        ],
    ]);

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);

    $tenant = Tenant::create([
        'id' => 'foo' . Str::random(10),
    ]);

    expect($tenant->database()->manager() instanceof MySQLDatabaseManager)->toBeTrue();

    tenancy()->initialize($tenant); // check if everything works
    tenancy()->end();

    config(['tenancy.database.managers.mysql' => PermissionControlledMySQLDatabaseManager::class]);

    tenancy()->initialize($tenant); // check if everything works

    expect($tenant->database()->manager() instanceof PermissionControlledMySQLDatabaseManager)->toBeTrue();
    expect(config('database.connections.tenant.username'))->toBe('root');
});
