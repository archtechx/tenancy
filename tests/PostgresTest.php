<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Tests\Etc\Post;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Events\TenancyEnded;
use Illuminate\Database\Schema\Blueprint;
use Stancl\Tenancy\Bootstrappers\Integrations\PostgresTenancyBootstrapper;
use Stancl\Tenancy\Tests\Etc\ScopedComment;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Jobs\DeleteTenantsPostgresUser;
use Stancl\Tenancy\Jobs\CreatePostgresUserForTenant;
use Stancl\Tenancy\Listeners\RevertToCentralContext;

beforeEach(function () {
    DB::purge('central');

    config(['tenancy.bootstrappers' => [PostgresTenancyBootstrapper::class]]);

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);

    config(['database.connections.central' => config('database.connections.pgsql')]);
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
    Schema::dropIfExists('domains');
    Schema::dropIfExists($tenantTable);

    // todo1 The Post/Comment models have non-UUID primary keys
    Schema::create($tenantTable, function (Blueprint $table) {
        $table->uuid('id')->default(Str::uuid()->toString())->nullable(false)->primary();
        $table->timestamps();
        $table->json('data')->nullable();
    });

    Schema::create($primaryModel->getTable(), function (Blueprint $table) {
        $table->uuid('id')->default(Str::uuid()->toString())->nullable(false)->primary();
        $table->string('text');
        $table->foreignUuid('tenant_id')->constrained('tenants')->onUpdate('cascade')->onDelete('cascade');;
    });

    Schema::create($secondaryModel->getTable(), function (Blueprint $table) use ($primaryModel) {
        $table->uuid('id')->default(Str::uuid()->toString())->nullable(false)->primary();
        $table->string('text');
        $table->foreignUuid($primaryModel->getForeignKey())->constrained($primaryModel->getTable())->onUpdate('cascade')->onDelete('cascade');
    });
});

afterEach(function () {
    Schema::dropIfExists('comments');
    Schema::dropIfExists('posts');
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
    $getRlsTables = fn () => $modelTables->map(fn ($table) => DB::select('select relname, relrowsecurity, relforcerowsecurity from pg_class WHERE oid = ' . "'$table'::regclass"))->collapse();

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
    // Create rls policies for tables
    pest()->artisan('tenants:create-rls-policies');

    // Create two tenants with postgres users
    $tenant = Tenant::create();
    $secondTenant = Tenant::create();

    CreatePostgresUserForTenant::dispatchSync($tenant);
    CreatePostgresUserForTenant::dispatchSync($secondTenant);

    // Create posts and comments for both tenants
    tenancy()->initialize($tenant);

    $post1 = Post::create(['text' => 'first post']);
    $post1Comment = $post1->scoped_comments()->create(['text' => 'first comment']);

    tenancy()->end();

    tenancy()->initialize($secondTenant);

    $post2 = Post::create(['text' => 'second post']);
    $post2Comment = $post2->scoped_comments()->create(['text' => 'second comment']);

    tenancy()->end();

    // todo1 Add option to disable the global scopes that the BelongsToTenant trait adds to the models, make RLS scope the queries
    // Ensure RLS scopes the queries – expect that tenants cannot access the records (posts and comments) of other tenants
    tenancy()->initialize($tenant);

    expect(Post::all()->pluck('text'))
        ->toContain($post1->text)
        ->not()->toContain($post2->text);

    expect(ScopedComment::all()->pluck('text'))
        ->toContain($post1Comment->text)
        ->not()->toContain($post2Comment->text);

    tenancy()->end();

    tenancy()->initialize($secondTenant);

    expect(Post::all()->pluck('text'))->toContain($post2->text)->not()->toContain($post1->text);
    expect(ScopedComment::all()->pluck('text'))->toContain($post2Comment->text)->not()->toContain($post1Comment->text);

    tenancy()->end();
})->group('test');
