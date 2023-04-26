<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Stancl\Tenancy\Jobs\DeleteTenantsPostgresUser;
use Stancl\Tenancy\Jobs\CreatePostgresUserForTenant;
use Stancl\Tenancy\Actions\CreateRLSPoliciesForTables;

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

test('correct rls policies get created', function(bool $action) {
    config([
        'tenancy.models.rls' => [
            Post::class, // Primary model (directly belongs to tenant)
        ],
    ]);

    Schema::dropIfExists('comments');
    Schema::dropIfExists('posts');

    Schema::create('posts', function (Blueprint $table) {
        $table->increments('id');
        $table->string('text');

        $table->string('tenant_id');

        $table->foreign('tenant_id')->references('id')->on('tenants')->onUpdate('cascade')->onDelete('cascade');
    });

    Schema::create('comments', function (Blueprint $table) {
        $table->increments('id');
        $table->string('text');

        $table->unsignedInteger('post_id');

        $table->foreign('post_id')->references('id')->on('posts')->onUpdate('cascade')->onDelete('cascade');
    });

    $getRlsPolicies = fn () => DB::select('select * from pg_policies');
    $getModelTables = fn () => collect(config('tenancy.models.rls'))->map(fn (string $model) => (new $model)->getTable());
    $getRlsTables = fn() => $getModelTables()->map(fn ($table) => DB::select('select relname, relrowsecurity, relforcerowsecurity from pg_class WHERE oid = ' . "'$table'::regclass"))->collapse();

    expect($getRlsPolicies())->toHaveCount(0);

    if ($action) {
        CreateRLSPoliciesForTables::handle();
    } else {
        pest()->artisan('tenants:create-rls-policies');
    }

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

    if ($action) {
        CreateRLSPoliciesForTables::handle();
    } else {
        pest()->artisan('tenants:create-rls-policies');
    }

    // Check if tables with policies are RLS protected (even the ones not directly related to the tenant)
    // Models related to tenant through some model must use the BelongsToPrimaryModel trait to work properly
    expect($getRlsPolicies())->toHaveCount(count(config('tenancy.models.rls'))); // 2
    expect($getRlsTables())->toHaveCount(count(config('tenancy.models.rls'))); // 2

    foreach ($getRlsTables() as $table) {
        expect($getModelTables())->toContain($table->relname);
        expect($table->relforcerowsecurity)->toBeTrue();
    }
})->with([
    'action' => true,
    'command' => false
]);
