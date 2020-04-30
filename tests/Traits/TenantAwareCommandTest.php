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
    public function commands_run_globally_are_tenant_aware_and_return_valid_exit_code()
    {
        $tenant1 = Tenant::new()->save();
        $tenant2 = Tenant::new()->save();
        \Artisan::call('tenants:migrate', [
            '--tenants' => [$tenant1['id'], $tenant2['id']],
        ]);

        $this->artisan('user:add')
            ->assertExitCode(0);

        tenancy()->initializeTenancy($tenant1);
        $this->assertNotEmpty(\DB::table('users')->get());
        tenancy()->end();

        tenancy()->initializeTenancy($tenant2);
        $this->assertNotEmpty(\DB::table('users')->get());
        tenancy()->end();
    }
}
