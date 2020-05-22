<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Tests\Etc\Tenant;

class TenantAwareCommandTest extends TestCase
{
    /** @test */
    public function commands_run_globally_are_tenant_aware_and_return_valid_exit_code()
    {
        $tenant1 = Tenant::create();
        $tenant2 = Tenant::create();
        Artisan::call('tenants:migrate', [
            '--tenants' => [$tenant1['id'], $tenant2['id']],
        ]);

        $this->artisan('user:add')
            ->assertExitCode(0);

        tenancy()->initialize($tenant1);
        $this->assertNotEmpty(DB::table('users')->get());
        tenancy()->end();

        tenancy()->initialize($tenant2);
        $this->assertNotEmpty(DB::table('users')->get());
        tenancy()->end();
    }
}
