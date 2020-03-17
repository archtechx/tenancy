<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Exceptions\DomainsOccupiedByOtherTenantException;
use Stancl\Tenancy\Exceptions\TenantDoesNotExistException;
use Stancl\Tenancy\Exceptions\TenantWithThisIdAlreadyExistsException;
use Stancl\Tenancy\Jobs\QueuedTenantDatabaseCreator;
use Stancl\Tenancy\Jobs\QueuedTenantDatabaseMigrator;
use Stancl\Tenancy\Jobs\QueuedTenantDatabaseSeeder;
use Stancl\Tenancy\Tenant;
use Stancl\Tenancy\TenantManager;
use Stancl\Tenancy\Tests\Etc\ExampleSeeder;

class TenantManagerTest extends TestCase
{
    public $autoCreateTenant = false;
    public $autoInitTenancy = false;

    /** @test */
    public function current_tenant_can_be_retrieved_using_getTenant()
    {
        $tenant = Tenant::new()->withDomains(['test2.localhost'])->save();

        tenancy()->init('test2.localhost');

        $this->assertEquals($tenant, tenancy()->getTenant());
    }

    /** @test */
    public function initById_works()
    {
        $tenant = Tenant::new()->withDomains(['foo.localhost'])->save();

        $this->assertNotEquals($tenant, tenancy()->getTenant());

        tenancy()->initById($tenant['id']);

        $this->assertEquals($tenant, tenancy()->getTenant());
    }

    /** @test */
    public function findByDomain_works()
    {
        $tenant = Tenant::new()->withDomains(['foo.localhost'])->save();

        $this->assertEquals($tenant, tenancy()->findByDomain('foo.localhost'));
    }

    /** @test */
    public function find_works()
    {
        Tenant::new()->withDomains(['dev.localhost'])->save();
        tenancy()->init('dev.localhost');

        $this->assertEquals(tenant(), tenancy()->find(tenant('id')));
    }

    /** @test */
    public function findByDomain_throws_an_exception_when_an_unused_domain_is_supplied()
    {
        $this->expectException(\Exception::class);
        tenancy()->findByDomain('nonexistent.domain');
    }

    /** @test */
    public function tenancy_can_be_ended()
    {
        $originals = [
            'databaseName' => DB::connection()->getDatabaseName(),
            'storage_path' => storage_path(),
            'storage_root' => Storage::disk('local')->getAdapter()->getPathPrefix(),
            'cache' => app('cache'),
        ];

        // Verify that these assertions are the right way for testing this
        $this->assertSame($originals['databaseName'], DB::connection()->getDatabaseName());
        $this->assertSame($originals['storage_path'], storage_path());
        $this->assertSame($originals['storage_root'], Storage::disk('local')->getAdapter()->getPathPrefix());
        $this->assertSame($originals['cache'], app('cache'));

        Tenant::new()->withDomains(['foo.localhost'])->save();
        tenancy()->init('foo.localhost');

        $this->assertNotSame($originals['databaseName'], DB::connection()->getDatabaseName());
        $this->assertNotSame($originals['storage_path'], storage_path());
        $this->assertNotSame($originals['storage_root'], Storage::disk('local')->getAdapter()->getPathPrefix());
        $this->assertNotSame($originals['cache'], app('cache'));

        tenancy()->endTenancy();

        $this->assertSame($originals['databaseName'], DB::connection()->getDatabaseName());
        $this->assertSame($originals['storage_path'], storage_path());
        $this->assertSame($originals['storage_root'], Storage::disk('local')->getAdapter()->getPathPrefix());
        $this->assertSame($originals['cache'], app('cache'));
    }

    /** @test */
    public function tenancy_can_be_ended_after_reidentification()
    {
        $originals = [
            'databaseName' => DB::connection()->getDatabaseName(),
            'storage_path' => storage_path(),
            'storage_root' => Storage::disk('local')->getAdapter()->getPathPrefix(),
            'cache' => app('cache'),
        ];

        Tenant::new()->withDomains(['foo.localhost'])->save();
        tenancy()->init('foo.localhost');

        $this->assertNotSame($originals['databaseName'], DB::connection()->getDatabaseName());
        $this->assertNotSame($originals['storage_path'], storage_path());
        $this->assertNotSame($originals['storage_root'], Storage::disk('local')->getAdapter()->getPathPrefix());
        $this->assertNotSame($originals['cache'], app('cache'));

        tenancy()->endTenancy();

        $this->assertSame($originals['databaseName'], DB::connection()->getDatabaseName());
        $this->assertSame($originals['storage_path'], storage_path());
        $this->assertSame($originals['storage_root'], Storage::disk('local')->getAdapter()->getPathPrefix());
        $this->assertSame($originals['cache'], app('cache'));

        // Reidentify tenant
        Tenant::new()->withDomains(['bar.localhost'])->save();
        tenancy()->init('bar.localhost');

        $this->assertNotSame($originals['databaseName'], DB::connection()->getDatabaseName());
        $this->assertNotSame($originals['storage_path'], storage_path());
        $this->assertNotSame($originals['storage_root'], Storage::disk('local')->getAdapter()->getPathPrefix());
        $this->assertNotSame($originals['cache'], app('cache'));

        tenancy()->endTenancy();

        $this->assertSame($originals['databaseName'], DB::connection()->getDatabaseName());
        $this->assertSame($originals['storage_path'], storage_path());
        $this->assertSame($originals['storage_root'], Storage::disk('local')->getAdapter()->getPathPrefix());
        $this->assertSame($originals['cache'], app('cache'));
    }

    /** @test */
    public function tenant_can_be_deleted()
    {
        $tenant = Tenant::new()->withDomains(['foo.localhost'])->save();
        $this->assertEquals([$tenant], tenancy()->all()->toArray());
        $tenant->delete();
        $this->assertEquals([], tenancy()->all()->toArray());
    }

    /** @test */
    public function all_returns_a_list_of_all_tenants()
    {
        $tenant1 = Tenant::new()->withDomains(['foo.localhost'])->save();
        $tenant2 = Tenant::new()->withDomains(['bar.localhost'])->save();
        $this->assertEqualsCanonicalizing([$tenant1, $tenant2], tenancy()->all()->toArray());
    }

    /** @test */
    public function data_can_be_passed_in_the_create_method()
    {
        $data = ['plan' => 'free', 'subscribed_until' => '2020-01-01'];
        $tenant = Tenant::create(['foo.localhost'], $data);

        $tenant_data = $tenant->data;
        unset($tenant_data['id']);

        $this->assertSame($data, $tenant_data);
    }

    /** @test */
    public function database_name_can_be_passed_in_the_create_method()
    {
        $database = 'abc' . $this->randomString();

        $tenant = tenancy()->create(['foo.localhost'], [
            '_tenancy_db_name' => $database,
        ]);

        $this->assertSame($database, $tenant->getDatabaseName());
    }

    /** @test */
    public function id_cannot_be_changed()
    {
        $tenant = Tenant::create(['test2.localhost']);

        $this->expectException(\Stancl\Tenancy\Exceptions\TenantStorageException::class);
        $tenant->id = 'bar';

        $tenant2 = Tenant::create(['test3.localhost']);

        $this->expectException(\Stancl\Tenancy\Exceptions\TenantStorageException::class);
        $tenant2->put('id', 'foo');
    }

    /** @test */
    public function all_returns_a_collection_of_tenant_objects()
    {
        Tenant::create('foo.localhost');
        $this->assertSame('Tenant', class_basename(tenancy()->all()[0]));
    }

    /** @test */
    public function Tenant_is_bound_correctly_to_the_service_container()
    {
        $this->assertSame(null, app(Tenant::class));
        $tenant = Tenant::create(['foo.localhost']);
        app(TenantManager::class)->initializeTenancy($tenant);
        $this->assertSame($tenant->id, app(Tenant::class)->id);
        $this->assertSame(app(Tenant::class), app(TenantManager::class)->getTenant());
        app(TenantManager::class)->endTenancy();
        $this->assertSame(app(Tenant::class), app(TenantManager::class)->getTenant());
    }

    /** @test */
    public function id_can_be_supplied_during_creation()
    {
        $id = 'abc' . $this->randomString();
        $this->assertSame($id, Tenant::create(['foo.localhost'], ['id' => $id])->id);
        $this->assertTrue(tenancy()->all()->contains(function ($tenant) use ($id) {
            return $tenant->id === $id;
        }));
    }

    /** @test */
    public function automatic_migrations_work()
    {
        $tenant = Tenant::create(['foo.localhost']);
        tenancy()->initialize($tenant);
        $this->assertFalse(\Schema::hasTable('users'));

        config(['tenancy.migrate_after_creation' => true]);
        $tenant2 = Tenant::create(['bar.localhost']);
        tenancy()->initialize($tenant2);
        $this->assertTrue(\Schema::hasTable('users'));
    }

    /** @test */
    public function automatic_seeding_works()
    {
        config(['tenancy.migrate_after_creation' => true]);

        $tenant = Tenant::create(['foo.localhost']);
        tenancy()->initialize($tenant);
        $this->assertSame(0, \DB::table('users')->count());

        config([
            'tenancy.seed_after_migration' => true,
            'tenancy.seeder_parameters' => [
                '--class' => ExampleSeeder::class,
            ],
        ]);

        $tenant2 = Tenant::create(['bar.localhost']);
        tenancy()->initialize($tenant2);
        $this->assertSame(1, \DB::table('users')->count());
    }

    /** @test */
    public function ensureTenantCanBeCreated_works()
    {
        $id = 'foo' . $this->randomString();
        Tenant::create(['foo.localhost'], ['id' => $id]);
        $this->expectException(DomainsOccupiedByOtherTenantException::class);
        Tenant::create(['foo.localhost']);

        $this->expectException(TenantWithThisIdAlreadyExistsException::class);
        Tenant::create(['bar.localhost'], ['id' => $id]);
    }

    /** @test */
    public function automigration_is_queued_when_db_creation_is_queued()
    {
        Queue::fake();

        config([
            'tenancy.queue_database_creation' => true,
            'tenancy.migrate_after_creation' => true,
        ]);

        $tenant = Tenant::new()->save();

        Queue::assertPushedWithChain(QueuedTenantDatabaseCreator::class, [
            QueuedTenantDatabaseMigrator::class,
        ]);

        // foreach (Queue::pushedJobs() as $job) {
        //     $job[0]['job']->handle(); // this doesn't execute the chained job
        // }
        // tenancy()->initialize($tenant);
        // $this->assertTrue(\Schema::hasTable('users'));
    }

    /** @test */
    public function autoseeding_is_queued_when_db_creation_is_queued()
    {
        Queue::fake();

        config([
            'tenancy.queue_database_creation' => true,
            'tenancy.migrate_after_creation' => true,
            'tenancy.seed_after_migration' => true,
        ]);

        Tenant::new()->save();

        Queue::assertPushedWithChain(QueuedTenantDatabaseCreator::class, [
            QueuedTenantDatabaseMigrator::class,
            QueuedTenantDatabaseSeeder::class,
        ]);
    }

    /** @test */
    public function TenantDoesNotExistException_is_thrown_when_find_is_called_on_an_id_that_does_not_belong_to_any_tenant()
    {
        $this->expectException(TenantDoesNotExistException::class);
        tenancy()->find('gjnfdgf');
    }

    /** @test */
    public function event_listeners_can_accept_arguments()
    {
        tenancy()->hook('tenant.creating', function ($tenantManager, $tenant) {
            $this->assertSame('bar', $tenant->foo);
        });

        Tenant::new()->withData(['foo' => 'bar'])->save();
    }

    /** @test */
    public function tenant_creating_hook_can_be_used_to_modify_tenants_data()
    {
        tenancy()->hook('tenant.creating', function ($tm, Tenant $tenant) {
            $tenant->put([
                'foo' => 'bar',
                'abc123' => 'def456',
            ]);
        });
        $tenant = Tenant::new()->save();

        $this->assertArrayHasKey('foo', $tenant->data);
        $this->assertArrayHasKey('abc123', $tenant->data);
    }

    /** @test */
    public function database_creation_can_be_disabled()
    {
        config(['tenancy.create_database' => false]);

        tenancy()->hook('database.creating', function () {
            $this->fail();
        });

        $tenant = Tenant::new()->save();

        $this->assertTrue(true);
    }

    /** @test */
    public function database_creation_can_be_disabled_for_specific_tenants()
    {
        config(['tenancy.create_database' => true]);

        tenancy()->hook('database.creating', function () {
            $this->assertTrue(true);
        });

        $tenant = Tenant::new()->save();

        tenancy()->hook('database.creating', function () {
            $this->fail();
        });

        $tenant2 = Tenant::new()->withData([
            '_tenancy_create_database' => false,
        ])->save();
    }
}
