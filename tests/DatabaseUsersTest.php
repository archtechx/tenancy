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
use Stancl\Tenancy\Exceptions\TenantDatabaseUserAlreadyExistsException;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager;
use Stancl\Tenancy\TenantDatabaseManagers\PermissionControlledMySQLDatabaseManager;
use Stancl\Tenancy\Tests\Etc\Tenant;

uses(Stancl\Tenancy\Tests\TestCase::class);

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
    $this->assertFalse($manager->userExists($tenant->database()->getUsername()));

    $tenant->save();

    $this->assertTrue($manager->userExists($tenant->database()->getUsername()));
});

test('a tenants database cannot be created when the user already exists', function () {
    $username = 'foo' . Str::random(8);
    $tenant = Tenant::create([
        'tenancy_db_username' => $username,
    ]);

    /** @var ManagesDatabaseUsers $manager */
    $manager = $tenant->database()->manager();
    $this->assertTrue($manager->userExists($tenant->database()->getUsername()));
    $this->assertTrue($manager->databaseExists($tenant->database()->getName()));

    $this->expectException(TenantDatabaseUserAlreadyExistsException::class);
    Event::fake([DatabaseCreated::class]);

    $tenant2 = Tenant::create([
        'tenancy_db_username' => $username,
    ]);

    /** @var ManagesDatabaseUsers $manager */
    $manager2 = $tenant2->database()->manager();

    // database was not created because of DB transaction
    $this->assertFalse($manager2->databaseExists($tenant2->database()->getName()));
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
    $this->assertStringStartsWith('GRANT CREATE, ALTER, ALTER ROUTINE ON', $query->{"Grants for {$user}@%"}); // @mysql because that's the hostname within the docker network
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

    $this->assertTrue($tenant->database()->manager() instanceof MySQLDatabaseManager);

    tenancy()->initialize($tenant); // check if everything works
    tenancy()->end();

    config(['tenancy.database.managers.mysql' => PermissionControlledMySQLDatabaseManager::class]);

    tenancy()->initialize($tenant); // check if everything works

    $this->assertTrue($tenant->database()->manager() instanceof PermissionControlledMySQLDatabaseManager);
    $this->assertSame('root', config('database.connections.tenant.username'));
});
