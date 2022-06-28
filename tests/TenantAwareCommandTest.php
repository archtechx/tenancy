<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Tests\Etc\Tenant;

uses(Stancl\Tenancy\Tests\TestCase::class);

test('commands run globally are tenant aware and return valid exit code', function () {
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
});
