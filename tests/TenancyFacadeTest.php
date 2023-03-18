<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Database\Models\Tenant as ModelsTenant;
use Stancl\Tenancy\Events\EndingTenancy;
use Stancl\Tenancy\Events\InitializingTenancy;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Facades\Tenancy;

class TenantFacadeTest extends TestCase
{
    /** @test */
    public function tenancy_events_not_dispatched_when_faked()
    {
        Event::fake();
        $tenant = Tenant::create(['id' => 'tenant_id']);

        // Asserting the Tenant is created inside the database
        Event::assertDispatched(TenantCreated::class);

        // Faking the Tenancy facade now, which will not dispatch any events
        Tenancy::fake();

        tenancy()->initialize($tenant);
        Event::assertNotDispatched(TenantRetrieved::class);
        Event::assertNotDispatched(InitializingTenancy::class);
        Event::assertNotDispatched(TenancyInitialized::class);

        tenancy()->end();
        Event::assertNotDispatched(EndingTenancy::class);
        Event::assertNotDispatched(TenancyEnded::class);
    }

    /** @test */
    public function tenancy_is_resolved_when_faked()
    {
        Event::fake();
        // The following line can be changed, by using Mockery | Not sure, not much experience
        $tenant = new Tenant(['id' => 'tenant_1']);

        // Faking the Tenancy facade now
        Tenancy::fake();

        // Fake initializing Tenancy using Tenant Model Instance
        tenancy()->initialize($tenant);
        Event::assertNotDispatched(TenantRetrieved::class);
        $this->assertEquals($tenant->id, tenant('id'));

        // Fake initializing Tenancy using string
        tenancy()->initialize($tenantId = 'tenant_2');
        Event::assertNotDispatched(TenantRetrieved::class);
        $this->assertEquals($tenantId, tenant('id'));
    }
}

class Tenant extends ModelsTenant
{
    protected $table = 'tenants';

    protected $dispatchesEvents = [
        'created' => TenantCreated::class,
        'retrieved' => TenantRetrieved::class,
    ];
}

class TenantRetrieved
{
    public function __construct(Tenant $tenant)
    {
        // 
    }
}

class TenantCreated
{
    public function __construct(Tenant $tenant)
    {
        // 
    }
}
