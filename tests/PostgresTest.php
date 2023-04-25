<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Stancl\Tenancy\Jobs\DeleteTenantsPostgresUser;
use Stancl\Tenancy\Jobs\CreatePostgresUserForTenant;

beforeEach(function () {
    DB::setDefaultConnection('pgsql');

    config(['tenancy.models.tenant' => Tenant::class]);
});

test('postgres user can get created using the job', function() {
    $tenant = Tenant::create();
    $name = $tenant->getTenantKey();

    $tenantHasPostgresUser = fn () => count(DB::select("SELECT usename FROM pg_user WHERE usename = '$name';")) > 0;

    expect($tenantHasPostgresUser())->toBeFalse();

    CreatePostgresUserForTenant::dispatchSync($tenant);

    expect($tenantHasPostgresUser())->toBeTrue();
});


test('postgres user can get deleted using the job', function() {
    $tenant = Tenant::create();
    $name = $tenant->getTenantKey();
    CreatePostgresUserForTenant::dispatchSync($tenant);

    $tenantHasPostgresUser = fn () => count(DB::select("SELECT usename FROM pg_user WHERE usename = '$name';")) > 0;

    expect($tenantHasPostgresUser())->toBeTrue();

    DeleteTenantsPostgresUser::dispatchSync($tenant);

    expect($tenantHasPostgresUser())->toBeFalse();
});

test('correct rls policies get created using the command', function() {
    config([
        'tenancy.models.rls' => $rlsModels = [
            Post::class, // Primary model (directly belongs to tenant)
            Comment::class, // Secondary model (belongs to tenant through Post)
        ],
    ]);
    $getRlsPolicies = fn () => DB::select('select * from pg_policies');

    expect($getRlsPolicies())->toHaveCount(0);
    pest()->artisan('tenants:create-rls-policies');
    expect($getRlsPolicies())->toHaveCount(count($rlsModels));
});
