<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Tests\RLS\Etc\Post;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Database\TenantScope;
use Illuminate\Database\Schema\Blueprint;
use Stancl\Tenancy\Tests\RLS\Etc\Article;
use Stancl\Tenancy\Tests\RLS\Etc\Comment;
use Stancl\Tenancy\Database\ParentModelScope;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;
use Stancl\Tenancy\Commands\CreateUserWithRLSPolicies;
use Stancl\Tenancy\RLS\PolicyManagers\TraitRLSManager;
use Stancl\Tenancy\Bootstrappers\PostgresRLSBootstrapper;
use Stancl\Tenancy\Database\Concerns\BelongsToPrimaryModel;
use function Stancl\Tenancy\Tests\pest;

beforeEach(function () {
    TraitRLSManager::$implicitRLS = true;
    TraitRLSManager::$modelDirectories = [__DIR__ . '/Etc'];
    TraitRLSManager::$excludedModels = [Article::class];

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);

    DB::purge($centralConnection = config('tenancy.database.central_connection'));
    config(['database.connections.' . $centralConnection => config('database.connections.pgsql')]);
    config(['tenancy.models.tenant_key_column' => 'tenant_id']);
    config(['tenancy.models.tenant' => Tenant::class]);
    config(['tenancy.rls.manager' => TraitRLSManager::class]);
    config(['tenancy.rls.user' => [
        'username' => 'username',
        'password' => 'password',
    ]]);

    config(['tenancy.bootstrappers' => [PostgresRLSBootstrapper::class]]);

    pest()->artisan('migrate:fresh', [
        '--force' => true,
        '--path' => __DIR__ . '/../../assets/migrations',
        '--realpath' => true,
    ]);

    Schema::create('posts', function (Blueprint $table) {
        $table->id();
        $table->string('text');
        $table->string('tenant_id');
        $table->foreign('tenant_id')->references('id')->on('tenants')->onUpdate('cascade')->onDelete('cascade');

        $table->timestamps();
    });

    Schema::create('comments', function (Blueprint $table) {
        $table->id();
        $table->string('text');
        $table->foreignId('post_id')->constrained('posts')->onUpdate('cascade')->onDelete('cascade');

        $table->timestamps();
    });

    // Exists to check that the manager doesn't generate queries for models excluded from model discovery
    Schema::create('articles', function (Blueprint $table) {
        $table->id();
        $table->string('text');
        $table->timestamps();
    });
});

test('correct rls policies get created with the correct hash using trait manager', function () {
    $manager = app(TraitRLSManager::class);

    // Tables that are directly or indirectly related to the tenant
    $tables = collect($manager->getModels())
        ->filter(fn (Model $model) => $manager->modelBelongsToTenant($model) || $manager->modelBelongsToTenantIndirectly($model))
        ->map(fn (Model $model) => $model->getTable())
        ->values()
        ->unique()
        ->toArray();

    $getRLSPolicies = fn () => DB::select('SELECT policyname, tablename FROM pg_policies');
    $getRLSTables = fn () => collect($tables)->map(fn ($table) => DB::select('SELECT relname, relforcerowsecurity FROM pg_class WHERE oid = ?::regclass', [$table]))->collapse();

    expect($getRLSPolicies())->toHaveCount(0);

    pest()->artisan('tenants:rls');

    // Check if all tables related to the tenant have RLS policies
    expect($policies = $getRLSPolicies())->toHaveCount(count($tables));
    expect($rlsTables = $getRLSTables())->toHaveCount(count($tables));

    foreach ($rlsTables as $table) {
        expect($tables)->toContain($table->relname);
        expect($table->relforcerowsecurity)->toBeTrue();
    }

    // Check that the policies get suffixed with the correct hash
    $queries = $manager->generateQueries();
    expect(array_keys($queries))->toEqualCanonicalizing($tables);
    expect(array_keys($queries))->not()->toContain('articles');

    /** @var CreateUserWithRLSPolicies $policyCreationCommand */
    $policyCreationCommand = app(CreateUserWithRLSPolicies::class);

    foreach ($queries as $table => $query) {
        $policy = collect($policies)->filter(fn (object $policy) => $policy->tablename === $table)->first();
        $hash = $policyCreationCommand->hashPolicy($query)[0];
        $policyNameWithHash = "{$table}_rls_policy_{$hash}";

        expect($tables)->toContain($policy->tablename);
        expect($policy->policyname)->toBe($policyNameWithHash);
    }
});

test('global scope is not applied when using rls with single db traits', function () {
    // The global scopes (TenantScope and ParentModelScope) are added to models
    // that are using the single DB traits (BelongsToTenant and BelongsToPrimaryModel)
    // if TraitRLSManager::$implicitRLS is false and the model does not implement RLSModel
    TraitRLSManager::$implicitRLS = false;

    // Post model uses BelongsToTenant
    // Comment uses BelongsToPrimaryModel
    // Both models implement RLSModel, so they shouldn't have the global scope
    expect(Post::make()->hasGlobalScope(TenantScope::class))->toBeFalse();
    expect(Comment::make()->hasGlobalScope(ParentModelScope::class))->toBeFalse();

    // These models DO NOT implement RLSModel
    expect(NonRLSPost::make()->hasGlobalScope(TenantScope::class))->toBeTrue();
    expect(NonRLSComment::make()->hasGlobalScope(ParentModelScope::class))->toBeTrue();

    TraitRLSManager::$implicitRLS = true;
    NonRLSPost::clearBootedModels();
    NonRLSComment::clearBootedModels();

    // Both NonRLSPost and NonRLSComment use the single DB traits, but don't implement RLSModel
    // The models still shouldn't have the global scope because RLS is enabled implicitly
    expect(NonRLSPost::make()->hasGlobalScope(TenantScope::class))->toBeFalse();
    expect(NonRLSComment::make()->hasGlobalScope(ParentModelScope::class))->toBeFalse();
});

test('queries are correctly scoped using RLS with trait rls manager', function (bool $implicitRLS) {
    TraitRLSManager::$implicitRLS = $implicitRLS;

    $postModel = $implicitRLS ? NonRLSPost::class : Post::class;
    $commentModel = $implicitRLS ? NonRLSComment::class : Comment::class;

    // Create RLS policies for tables and the tenant user
    pest()->artisan('tenants:rls');

    // Create two tenants
    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();

    // Create posts and comments for both tenants
    tenancy()->initialize($tenant1);

    $post1 = $postModel::create([
        'text' => 'first post',
    ]);

    $post1Comment = $commentModel::create(['text' => 'first comment', 'post_id' => $post1->id]);

    tenancy()->initialize($tenant2);

    $post2 = $postModel::create([
        'text' => 'second post',
    ]);

    $post2Comment = $commentModel::create(['text' => 'second comment', 'post_id' => $post2->id]);

    tenancy()->initialize($tenant1);

    expect($postModel::all()->pluck('text'))
        ->toHaveCount(1)
        ->toContain($post1->text)
        ->not()->toContain($post2->text)
        ->toEqual($postModel::withoutGlobalScopes()->get()->pluck('text'));

    expect($commentModel::all()->pluck('text'))
        ->toHaveCount(1)
        ->toContain($post1Comment->text)
        ->not()->toContain($post2Comment->text)
        ->toEqual($commentModel::withoutGlobalScopes()->get()->pluck('text'));

    tenancy()->end();

    expect($postModel::all()->pluck('text'))
        ->toHaveCount(2)
        ->toContain($post1->text)
        ->toContain($post2->text);

    expect($commentModel::all()->pluck('text'))
        ->toHaveCount(2)
        ->toContain($post1Comment->text)
        ->toContain($post2Comment->text);

    tenancy()->initialize($tenant2);

    expect($postModel::all()->pluck('text'))
        ->toHaveCount(1)
        ->toContain($post2->text)
        ->not()->toContain($post1->text)
        ->toEqual($postModel::withoutGlobalScopes()->get()->pluck('text'));

    expect($commentModel::all()->pluck('text'))
        ->toHaveCount(1)
        ->toContain($post2Comment->text)
        ->not()->toContain($post1Comment->text)
        ->toEqual($commentModel::withoutGlobalScopes()->get()->pluck('text'));

    // Test that RLS policies protect tenants from other tenant's direct queries
    DB::statement("UPDATE posts SET text = 'updated' WHERE id = {$post1->id}"); // should have no effect
    DB::statement("UPDATE comments SET text = 'updated'"); // should only update the current tenant's comments

    // Still in tenant2
    expect($commentModel::all()->pluck('text'))
        ->toContain('updated'); // query with no WHERE updated the current tenant's comments
    expect($postModel::all()->pluck('text'))
        ->toContain('second post'); // query with a where targeting another tenant's post had no effect on the current tenant's posts

    tenancy()->initialize($tenant1);

    expect($postModel::all()->pluck('text'))
        ->toContain($post1->text)
        ->not()->toContain($post2->text)
        ->not()->toContain('updated') // Text of tenant records was NOT changed to 'updated'
        ->toEqual($postModel::withoutGlobalScopes()->get()->pluck('text'));

    expect($commentModel::all()->pluck('text'))
        ->toContain($post1Comment->text)
        ->not()->toContain($post2Comment->text)
        ->not()->toContain('updated') // No change to posts either
        ->toEqual($commentModel::withoutGlobalScopes()->get()->pluck('text'));

    // Try deleting second tenant's records – should have no effect
    DB::statement("DELETE FROM posts WHERE id = {$post2->id}");
    DB::statement("DELETE FROM comments");

    // Still in tenant1
    expect($postModel::all())->toHaveCount(1); // query with a where targeting another tenant's post had no effect on the current tenant's posts
    expect($commentModel::all())->toHaveCount(0); // query with no WHERE updated the current tenant's comments

    tenancy()->initialize($tenant2);

    // Records weren't deleted by the first tenant
    expect($postModel::count())->toBe(1);
    expect($commentModel::count())->toBe(1);

    // Directly inserting records to other tenant's tables should fail (insufficient privilege error – new row violates row-level security policy)
    expect(fn () => DB::statement("INSERT INTO posts (text, tenant_id) VALUES ('third post', '{$tenant1->getTenantKey()}')"))
        ->toThrow(QueryException::class);

    expect(fn () => DB::statement("INSERT INTO comments (text, post_id) VALUES ('third comment', {$post1->id})"))
        ->toThrow(QueryException::class);
})->with([
    true,
    false
]);

test('trait rls manager generates queries correctly', function() {
    /** @var TraitRLSManager $manager */
    $manager = app(TraitRLSManager::class);

    // Three tables related to tenants – posts (directly), comments (indirectly)
    expect($manager->generateQueries())->toContain(
        <<<SQL
        CREATE POLICY posts_rls_policy ON posts USING (
            tenant_id::text = current_setting('my.current_tenant')
        );
        SQL,
        <<<SQL
        CREATE POLICY comments_rls_policy ON comments USING (
            post_id IN (
                SELECT id
                FROM posts
                WHERE tenant_id::text = current_setting('my.current_tenant')
            )
        );
        SQL,
    );
});

class NonRLSPost extends Model
{
    use BelongsToTenant;

    public $table = 'posts';

    protected $guarded = [];

    public $timestamps = false;

    public function comments(): HasMany
    {
        return $this->hasMany(NonRLSComment::class, 'post_id');
    }
}

class NonRLSComment extends Model
{
    use BelongsToPrimaryModel;

    public $table = 'comments';

    protected $guarded = [];

    public $timestamps = false;

    public function getRelationshipToPrimaryModel(): string
    {
        return 'post';
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(NonRLSPost::class, 'post_id');
    }
}
