<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stancl\Tenancy\Contracts\ManagesDatabaseUsers;
use Stancl\Tenancy\Exceptions\TenantDatabaseUserAlreadyExistsException;
use Stancl\Tenancy\Tenant;
use Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager;
use Stancl\Tenancy\TenantDatabaseManagers\PermissionControlledMySQLDatabaseManager;

class DatabaseUsersTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        config([
            'tenancy.database_managers.mysql' => PermissionControlledMySQLDatabaseManager::class,
            'tenancy.database.suffix' => '',
            'tenancy.database.template_connection' => 'mysql',
        ]);
    }

    /** @test */
    public function users_are_created_when_permission_controlled_mysql_manager_is_used()
    {
        $tenant = Tenant::new()->withData([
            'id' => 'foo' . Str::random(10),
        ]);
        $tenant->database()->makeCredentials();

        /** @var ManagesDatabaseUsers $manager */
        $manager = $tenant->database()->manager();
        $this->assertFalse($manager->userExists($tenant->database()->getUsername()));

        $tenant->save();

        $this->assertTrue($manager->userExists($tenant->database()->getUsername()));
    }

    /** @test */
    public function a_tenants_database_cannot_be_created_when_the_user_already_exists()
    {
        $username = 'foo' . Str::random(8);
        $tenant = Tenant::new()->withData([
            '_tenancy_db_username' => $username,
        ])->save();

        /** @var ManagesDatabaseUsers $manager */
        $manager = $tenant->database()->manager();
        $this->assertTrue($manager->userExists($tenant->database()->getUsername()));
        $this->assertTrue($manager->databaseExists($tenant->database()->getName()));

        $this->expectException(TenantDatabaseUserAlreadyExistsException::class);
        $tenant2 = Tenant::new()->withData([
            '_tenancy_db_username' => $username,
        ])->save();

        /** @var ManagesDatabaseUsers $manager */
        $manager = $tenant2->database()->manager();
        // database was not created because of DB transaction
        $this->assertFalse($manager->databaseExists($tenant2->database()->getName()));
    }

    /** @test */
    public function correct_grants_are_given_to_users()
    {
        PermissionControlledMySQLDatabaseManager::$grants = [
            'ALTER', 'ALTER ROUTINE', 'CREATE',
        ];

        $tenant = Tenant::new()->withData([
            '_tenancy_db_username' => $user = 'user' . Str::random(8),
        ])->save();

        $query = DB::connection('mysql')->select("SHOW GRANTS FOR `{$tenant->database()->getUsername()}`@`{$tenant->database()->connection()['host']}`")[1];
        $this->assertStringStartsWith('GRANT CREATE, ALTER, ALTER ROUTINE ON', $query->{"Grants for {$user}@mysql"}); // @mysql because that's the hostname within the docker network
    }

    /** @test */
    public function having_existing_databases_without_users_and_switching_to_permission_controlled_mysql_manager_doesnt_break_existing_dbs()
    {
        config([
            'tenancy.database_managers.mysql' => MySQLDatabaseManager::class,
            'tenancy.database.suffix' => '',
            'tenancy.database.template_connection' => 'mysql',
        ]);

        $tenant = Tenant::new()->withData([
            'id' => 'foo' . Str::random(10),
        ])->save();

        $this->assertTrue($tenant->database()->manager() instanceof MySQLDatabaseManager);

        $tenant = Tenant::new()->withData([
            'id' => 'foo' . Str::random(10),
        ])->save();

        tenancy()->initialize($tenant); // check if everything works
        tenancy()->end();

        config(['tenancy.database_managers.mysql' => PermissionControlledMySQLDatabaseManager::class]);

        tenancy()->initialize($tenant); // check if everything works

        $this->assertTrue($tenant->database()->manager() instanceof PermissionControlledMySQLDatabaseManager);
        $this->assertSame('root', config('database.connections.tenant.username'));
    }
}
