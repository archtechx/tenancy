<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Tests\Etc\Post;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Database\TenantScope;
use Illuminate\Database\Schema\Blueprint;
use Stancl\Tenancy\Tests\Etc\ScopedComment;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Database\Concerns\RlsModel;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Jobs\DeleteTenantsPostgresUser;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Stancl\Tenancy\Jobs\CreatePostgresUserForTenant;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Bootstrappers\Integrations\PostgresRLSBootstrapper;

beforeEach(function () {
    DB::purge($centralConnection = config('tenancy.database.central_connection'));

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);

    // Turn RLS scoping on
    config(['tenancy.rls.enabled' => false]);
    config(['tenancy.rls.model_directories' => [__DIR__ . '/Etc']]);
    config(['tenancy.bootstrappers' => [PostgresRLSBootstrapper::class]]);
    config(['database.connections.' . $centralConnection => config('database.connections.pgsql')]);
    config(['tenancy.models.tenant_key_column' => 'tenant_id']);
    config(['tenancy.models.tenant' => $tenantClass = Tenant::class]);

    CreatePostgresUserForTenant::$permissions = ['ALL'];

    $tenantModel = new $tenantClass;
    $primaryModel = new Post;
    $secondaryModel = new ScopedComment;

    $tenantTable = $tenantModel->getTable();

    DB::transaction(function () use ($tenantTable, $primaryModel, $secondaryModel) {
        // Drop all existing policies
        foreach (DB::select('select * from pg_policies') as $policy) {
            DB::statement("DROP POLICY IF EXISTS {$policy->policyname} ON {$policy->tablename}");
        }

        // Drop tables of the tenant, primary and secondary model
        Schema::dropIfExists('domains');
        DB::statement("DROP TABLE IF EXISTS {$secondaryModel->getTable()} CASCADE");
        DB::statement("DROP TABLE IF EXISTS {$primaryModel->getTable()} CASCADE");
        DB::statement("DROP TABLE IF EXISTS $tenantTable CASCADE");

        // Manually create tables for the models
        Schema::create($tenantTable, function (Blueprint $table) {
            $table->string('id')->primary();
            $table->timestamps();
            $table->json('data')->nullable();
        });

        Schema::create($primaryModel->getTable(), function (Blueprint $table) {
            $table->id();
            $table->string('text');
            $table->string($tenantKeyColumn = config('tenancy.models.tenant_key_column'));

            $table->timestamps();
            $table->foreign($tenantKeyColumn)->references(tenancy()->model()->getKeyName())->on(tenancy()->model()->getTable())->onUpdate('cascade')->onDelete('cascade');
        });

        Schema::create($secondaryModel->getTable(), function (Blueprint $table) use ($primaryModel) {
            $table->id();
            $table->string('text');
            $table->unsignedBigInteger($primaryModel->getForeignKey());

            $table->timestamps();
            $table->foreign($primaryModel->getForeignKey())->references($primaryModel->getKeyName())->on($primaryModel->getTable())->onUpdate('cascade')->onDelete('cascade');
        });
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
    $tenantModels = tenancy()->getTenantModels();
    $modelTables = collect($tenantModels)->map(fn (Model $model) => $model->getTable());
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
    expect($getRlsPolicies())->toHaveCount(count($tenantModels)); // 2
    expect($getRlsTables())->toHaveCount(count($tenantModels)); // 2

    foreach ($getRlsTables() as $table) {
        expect($modelTables)->toContain($table->relname);
        expect($table->relforcerowsecurity)->toBeTrue();
    }
});

test('global scope is not applied when using rls', function () {
    // By default, TenantScope is added to models using BelongsToTenant
    // If config('tenancy.rls.enabled') is false (which it is by default)
    expect(Post::hasGlobalScope(TenantScope::class))->toBeTrue();

    // Clear booted models to forget the global scope and see if it gets applied during the boot
    RlsPost::clearBootedModels();
    RlsPost::bootBelongsToTenant();

    // RlsPost implements the RlsModel interface
    // The model shouldn't have the global scope
    expect(RlsPost::hasGlobalScope(TenantScope::class))->toBeFalse();

    config(['tenancy.rls.enabled' => true]);

    Post::clearBootedModels();
    Post::bootBelongsToTenant();

    expect(Post::hasGlobalScope(TenantScope::class))->toBeFalse();
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

    $post1 = RlsPost::create(['text' => 'first post']);
    $post1Comment = $post1->scoped_comments()->create(['text' => 'first comment']);

    tenancy()->initialize($secondTenant);

    $post2 = RlsPost::create(['text' => 'second post']);
    $post2Comment = $post2->scoped_comments()->create(['text' => 'second comment']);

    tenancy()->initialize($tenant);

    expect(RlsPost::all()->pluck('text'))
        ->toContain($post1->text)
        ->not()->toContain($post2->text);

    expect(ScopedComment::all()->pluck('text'))
        ->toContain($post1Comment->text)
        ->not()->toContain($post2Comment->text);

    tenancy()->end();

    expect(RlsPost::all()->pluck('text'))
        ->toContain($post1->text)
        ->toContain($post2->text);

    expect(ScopedComment::all()->pluck('text'))
        ->toContain($post1Comment->text)
        ->toContain($post2Comment->text);

    tenancy()->initialize($secondTenant);

    expect(RlsPost::all()->pluck('text'))->toContain($post2->text)->not()->toContain($post1->text);
    expect(ScopedComment::all()->pluck('text'))->toContain($post2Comment->text)->not()->toContain($post1Comment->text);

    tenancy()->initialize($tenant);

    expect(RlsPost::all()->pluck('text'))
        ->toContain($post1->text)
        ->not()->toContain($post2->text);

    expect(ScopedComment::all()->pluck('text'))
        ->toContain($post1Comment->text)
        ->not()->toContain($post2Comment->text);
});

test('users created by CreatePostgresUserForTenant are only granted the permissions specified in the static property', function() {
    CreatePostgresUserForTenant::$permissions = ['INSERT', 'SELECT', 'UPDATE'];
    $tenant = Tenant::create();
    $name = $tenant->getTenantKey();
    CreatePostgresUserForTenant::dispatchSync($tenant);

    $grants = array_map(fn (object $grant) => $grant->privilege_type, DB::select("SELECT * FROM information_schema.role_table_grants WHERE grantee = '$name';"));

    expect($grants)->toContain(...CreatePostgresUserForTenant::$permissions)
        ->not()->toContain('DELETE');
});

test('model discovery gets the models correctly', function() {
    // 'tenancy.rls.model_directories' is set to [__DIR__ . '/Etc'] in beforeEach
    // Check that the Post and ScopedComment models are found in the directory
    $expectedModels = [Post::class, ScopedComment::class];

    $foundModels = tenancy()->getModels()->where(function (Model $model) use ($expectedModels) {
        return in_array($model::class, $expectedModels);
    });

    expect($foundModels)->toHaveCount(count($expectedModels));
});

trait UsesUuidAsPrimaryKey
{
    use HasUuids;

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $uuid = Str::uuid()->toString();

            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = $uuid;
            }
        });
    }
}
/**
 * Post model that implements the RlsModel interface.
 */
class RlsPost extends Post implements RlsModel
{
    public function getForeignKey()
    {
        return 'post_id';
    }
}
