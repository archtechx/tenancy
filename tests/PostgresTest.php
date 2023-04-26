<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Stancl\Tenancy\Jobs\DeleteTenantsPostgresUser;
use Stancl\Tenancy\Jobs\CreatePostgresUserForTenant;

beforeEach(function () {
    DB::setDefaultConnection('pgsql');

    config(['tenancy.models.tenant' => Tenant::class]);

    foreach (DB::select('select * from pg_policies') as $policy) {
        DB::statement("DROP POLICY IF EXISTS {$policy->policyname} ON {$policy->tablename};");
    }
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
        'tenancy.models.rls' => [
            Post::class, // Primary model (directly belongs to tenant)
        ],
    ]);

    $tableExists = DB::selectOne('SELECT EXISTS (
        SELECT 1
        FROM pg_tables
        WHERE schemaname = ? AND tablename = ?
    ) AS table_exists;', ['pg_catalog', 'pg_policies'])->table_exists;

    dd($tableExists);

    $getRlsPolicies = fn () => DB::select('select * from pg_policies');
    $getModelTables = fn () => collect(config('tenancy.models.rls'))->map(fn (string $model) => (new $model)->getTable());
    $getRlsTables = fn() => $getModelTables()->map(fn ($table) => DB::select('select relname, relrowsecurity, relforcerowsecurity from pg_class WHERE oid = ' . "'$table'::regclass"))->collapse();

    expect($getRlsPolicies())->toHaveCount(0);
    pest()->artisan('tenants:create-rls-policies');
    expect($getRlsPolicies())->toHaveCount(count(config('tenancy.models.rls'))); // 1
    expect($getRlsTables())->toHaveCount(count(config('tenancy.models.rls'))); // 1
    // Check if tables with policies are RLS protected
    foreach ($getRlsTables() as $table) {
        expect($getModelTables())->toContain($table->relname);
        expect($table->relforcerowsecurity)->toBeTrue();
    }

    config([
        'tenancy.models.rls' => array_merge([
            ScopedComment::class, // Add secondary model to RLS protected models (belongs to tenant through Post)
        ], config('tenancy.models.rls')),
    ]);

    pest()->artisan('tenants:create-rls-policies');
    // Check if tables with policies are RLS protected (even the ones not directly related to the tenant)
    // Models related to tenant through some model must use the BelongsToPrimaryModel trait to work properly
    expect($getRlsPolicies())->toHaveCount(count(config('tenancy.models.rls'))); // 2
    expect($getRlsTables())->toHaveCount(count(config('tenancy.models.rls'))); // 2

    foreach ($getRlsTables() as $table) {
        expect($getModelTables())->toContain($table->relname);
        expect($table->relforcerowsecurity)->toBeTrue();
    }
});
