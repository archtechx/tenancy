<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Tests\Etc\Post;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Stancl\Tenancy\Jobs\DeleteTenantsPostgresUser;
use Stancl\Tenancy\Jobs\CreatePostgresUserForTenant;
use Stancl\Tenancy\Tests\Etc\ScopedComment;

beforeEach(function () {
    DB::setDefaultConnection('pgsql');

    config(['tenancy.models.tenant_key_column' => 'tenant_id']);
    config(['tenancy.models.tenant' => $tenantClass = Tenant::class]);
    config(['tenancy.models.rls' => [
        $primaryModelClass = Post::class, // Primary model (directly belongs to tenant)
        $secondaryModelClass = ScopedComment::class, // Secondary model (belongs to tenant through a primary model)
    ]]);

    $tenantModel = new $tenantClass;
    $primaryModel = new $primaryModelClass;
    $secondaryModel = new $secondaryModelClass;

    $tenantTable = $tenantModel->getTable();

    // Drop all existing policies
    foreach (DB::select('select * from pg_policies') as $policy) {
        DB::statement("DROP POLICY IF EXISTS {$policy->policyname} ON {$policy->tablename};");
    }

    Schema::dropIfExists($secondaryModel->getTable());
    Schema::dropIfExists($primaryModel->getTable());

    if(! Schema::hasTable($tenantTable)) {
        Schema::create($tenantTable, function (Blueprint $table) {
            $table->string('id')->primary();

            $table->timestamps();
            $table->json('data')->nullable();
        });
    }

    Schema::create($primaryModel->getTable(), function (Blueprint $table) use ($tenantTable) {
        $table->increments('id');
        $table->string('text');
        $table->string($tenantKey = config('tenancy.models.tenant_key_column'));

        $table->foreign($tenantKey)->references('id')->on($tenantTable)->onUpdate('cascade')->onDelete('cascade');
    });

    Schema::create($secondaryModel->getTable(), function (Blueprint $table) use ($primaryModel) {
        $table->increments('id');
        $table->string('text');
        $table->unsignedInteger($primaryModel->getForeignKey());

        $table->foreign($primaryModel->getForeignKey())->references('id')->on($primaryModel->getTable())->onUpdate('cascade')->onDelete('cascade');
    });
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

test('correct rls policies get created', function () {
    $rlsModels = config('tenancy.models.rls');
    $modelTables = collect($rlsModels)->map(fn (string $model) => (new $model)->getTable());
    $getRlsPolicies = fn () => DB::select('select * from pg_policies');
    $getRlsTables = fn() => $modelTables->map(fn ($table) => DB::select('select relname, relrowsecurity, relforcerowsecurity from pg_class WHERE oid = ' . "'$table'::regclass"))->collapse();

    // Drop all existing policies to check if the command creates policies for multiple tables
    foreach ($getRlsPolicies() as $policy) {
        DB::statement("DROP POLICY IF EXISTS {$policy->policyname} ON {$policy->tablename}");
    }

    expect($getRlsPolicies())->toHaveCount(0);

    pest()->artisan('tenants:create-rls-policies');

    // Check if all tables with policies are RLS protected (even the ones not directly related to the tenant)
    // Models related to tenant through some model must use the BelongsToPrimaryModel trait
    // For the command to create the policy correctly for the model's table
    expect($getRlsPolicies())->toHaveCount(count($rlsModels)); // 2
    expect($getRlsTables())->toHaveCount(count($rlsModels)); // 2

    foreach ($getRlsTables() as $table) {
        expect($modelTables)->toContain($table->relname);
        expect($table->relforcerowsecurity)->toBeTrue();
    }
});

test('queries are correctly scoped using RLS', function() {
    // todo1
    // 1) create rls policies for tables
    // 2) create two tenants with postgres users
    // 3) create posts and comments for both tenants
    // 4) ensure RLS scopes the queries – disable the global scopes that the single-db tenancy traits add to the models
    // 5) expect that tenants cannot access the records (posts and comments) of other tenants
})->skip();
