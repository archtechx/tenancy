<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests\Traits;

use Stancl\Tenancy\Tenant;
use Stancl\Tenancy\Tests\TestCase;

class TenantAwareCommandTest extends TestCase
{
    public $autoCreateTenant = false;
    public $autoInitTenancy = false;

    /** @test */
    public function commands_run_globally_are_tenant_aware()
    {
        $tenant1 = Tenant::new()->save();
        $tenant2 = Tenant::new()->save();

        $this->artisan('user:add')->assertExitCode(1);

        tenancy()->initializeTenancy($tenant1);
        $this->assertNotEmpty(User::all());
        tenancy()->endTenancy()

        tenancy()->initializeTenancy($tenant2);
        $this->assertNotEmpty(User::all());
        tenancy()->endTenancy()
    }
}
