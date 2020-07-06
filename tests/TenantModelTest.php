<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Contracts;
use Stancl\Tenancy\Contracts\UniqueIdentifierGenerator;
use Stancl\Tenancy\Database\TenantCollection;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Stancl\Tenancy\UUIDGenerator;

class TenantModelTest extends TestCase
{
    /** @test */
    public function created_event_is_dispatched()
    {
        Event::fake([TenantCreated::class]);

        Event::assertNotDispatched(TenantCreated::class);

        Tenant::create();

        Event::assertDispatched(TenantCreated::class);
    }

    /** @test */
    public function current_tenant_can_be_resolved_from_service_container_using_typehint()
    {
        $tenant = Tenant::create();

        tenancy()->initialize($tenant);

        $this->assertSame($tenant->id, app(Contracts\Tenant::class)->id);

        tenancy()->end();

        $this->assertSame(null, app(Contracts\Tenant::class));
    }

    /** @test */
    public function id_is_generated_when_no_id_is_supplied()
    {
        config(['tenancy.id_generator' => UUIDGenerator::class]);

        $this->mock(UUIDGenerator::class, function ($mock) {
            return $mock->shouldReceive('generate')->once();
        });

        $tenant = Tenant::create();

        $this->assertNotNull($tenant->id);
    }

    /** @test */
    public function autoincrement_ids_are_supported()
    {
        Schema::drop('domains');
        Schema::table('tenants', function (Blueprint $table) {
            $table->bigIncrements('id')->change();
        });

        unset(app()[UniqueIdentifierGenerator::class]);

        $tenant1 = Tenant::create();
        $tenant2 = Tenant::create();

        $this->assertSame(1, $tenant1->id);
        $this->assertSame(2, $tenant2->id);
    }

    /** @test */
    public function custom_tenant_model_can_be_used()
    {
        $tenant = MyTenant::create();

        tenancy()->initialize($tenant);

        $this->assertTrue(tenant() instanceof MyTenant);
    }

    /** @test */
    public function custom_tenant_model_that_doesnt_extend_vendor_Tenant_model_can_be_used()
    {
        $tenant = AnotherTenant::create([
            'id' => 'acme',
        ]);

        tenancy()->initialize($tenant);

        $this->assertTrue(tenant() instanceof AnotherTenant);
    }

    /** @test */
    public function tenant_can_be_created_even_when_we_are_in_another_tenants_context()
    {
        config(['tenancy.bootstrappers' => [
            DatabaseTenancyBootstrapper::class,
        ]]);

        Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
        Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function ($event) {
            return $event->tenant;
        })->toListener());

        $tenant1 = Tenant::create([
            'id' => 'foo',
            'tenancy_db_name' => 'db' . Str::random(16),
        ]);

        tenancy()->initialize($tenant1);

        $tenant2 = Tenant::create([
            'id' => 'bar',
            'tenancy_db_name' => 'db' . Str::random(16),
        ]);

        tenancy()->end();

        $this->assertSame(2, Tenant::count());
    }

    /** @test */
    public function the_model_uses_TenantCollection()
    {
        Tenant::create();
        Tenant::create();

        $this->assertSame(2, Tenant::count());
        $this->assertTrue(Tenant::all() instanceof TenantCollection);
    }

    /** @test */
    public function a_command_can_be_run_on_a_collection_of_tenants()
    {
        Tenant::create([
            'id' => 't1',
            'foo' => 'bar',
        ]);
        Tenant::create([
            'id' => 't2',
            'foo' => 'bar',
        ]);

        Tenant::all()->runForEach(function ($tenant) {
            $tenant->update([
                'foo' => 'xyz',
            ]);
        });

        $this->assertSame('xyz', Tenant::find('t1')->foo);
        $this->assertSame('xyz', Tenant::find('t2')->foo);
    }
}

class MyTenant extends Tenant
{
    protected $table = 'tenants';
}

class AnotherTenant extends Model implements Contracts\Tenant
{
    protected $guarded = [];
    protected $table = 'tenants';

    public function getTenantKeyName(): string
    {
        return 'id';
    }

    public function getTenantKey()
    {
        return $this->getAttribute('id');
    }

    public function run(callable $callback)
    {
        $callback();
    }

    public function getInternal(string $key)
    {
        return $this->$key;
    }

    public function setInternal(string $key, $value)
    {
        $this->$key = $value;
    }
}
