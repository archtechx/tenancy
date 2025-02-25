<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Events\TenancyEnded;
use Illuminate\Database\Schema\Blueprint;
use Stancl\Tenancy\Tests\RLS\Etc\Article;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Commands\CreateUserWithRLSPolicies;
use Stancl\Tenancy\RLS\PolicyManagers\TableRLSManager;
use Stancl\Tenancy\RLS\PolicyManagers\TraitRLSManager;
use Stancl\Tenancy\Bootstrappers\PostgresRLSBootstrapper;
use function Stancl\Tenancy\Tests\pest;

beforeEach(function () {
    TraitRLSManager::$excludedModels = [Article::class];
    TraitRLSManager::$modelDirectories = [__DIR__ . '/Etc'];

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);

    DB::purge($centralConnection = config('tenancy.database.central_connection'));
    config(['database.connections.' . $centralConnection => config('database.connections.pgsql')]);
    config(['tenancy.models.tenant_key_column' => 'tenant_id']);
    config(['tenancy.models.tenant' => Tenant::class]);
    config(['tenancy.bootstrappers' => [PostgresRLSBootstrapper::class]]);
    config(['tenancy.rls.user' => [
        'username' => 'username',
        'password' => 'password',
    ]]);

    // Turn implicit RLS scoping on
    TraitRLSManager::$implicitRLS = true;

    pest()->artisan('migrate:fresh', [
        '--force' => true,
        '--path' => __DIR__ . '/../../assets/migrations',
        '--realpath' => true,
    ]);

    Schema::create('authors', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('tenant_id');

        $table->foreign('tenant_id')->comment('rls')->references('id')->on('tenants')->onUpdate('cascade')->onDelete('cascade');
        $table->timestamps();
    });

    Schema::create('posts', function (Blueprint $table) {
        $table->id();
        $table->string('text');

        // Multiple foreign keys to test if the table manager generates the paths correctly

        // Leads to the tenants table
        $table->string('tenant_id')->nullable();
        $table->foreign('tenant_id')->comment('rls')->references('id')->on('tenants')->onUpdate('cascade')->onDelete('cascade');

        // Leads to the tenants table – shortest path (because the tenant key column is nullable – excluded when choosing the shortest path)
        $table->foreignId('author_id')->comment('rls')->constrained('authors');

        $table->timestamps();
    });

    Schema::create('comments', function (Blueprint $table) {
        $table->id();
        $table->string('text');
        $table->foreignId('post_id')->comment('rls')->constrained('posts')->onUpdate('cascade')->onDelete('cascade');

        $table->timestamps();
    });
});

// Regression test for https://github.com/archtechx/tenancy/pull/1280
test('rls command doesnt fail when a view is in the database', function (string $manager) {
    DB::statement("
        CREATE VIEW post_comments AS
        SELECT
            comments.id AS comment_id,
            posts.id AS post_id
        FROM comments
        INNER JOIN posts
            ON posts.id = comments.post_id
    ");

    // Inherit RLS rules from joined tables
    DB::statement("ALTER VIEW post_comments SET (security_invoker = on)");

    config(['tenancy.rls.manager' => $manager]);

    // throws an exception without the patch
    pest()->artisan('tenants:rls');
})->with([
    TableRLSManager::class,
    TraitRLSManager::class,
])->throwsNoExceptions();

test('postgres user gets created using the rls command', function(string $manager) {
    config(['tenancy.rls.manager' => $manager]);

    pest()->artisan('tenants:rls');

    $name = config('tenancy.rls.user.username');

    expect(count(DB::select("SELECT usename FROM pg_user WHERE usename = '$name'")))->toBe(1);
})->with([
    TableRLSManager::class,
    TraitRLSManager::class,
]);

test('rls command creates rls policies only for tables that do not have them', function (string $manager) {
    config(['tenancy.rls.manager' => $manager]);

    // Posts + comments (2 tables) with trait manager (authors doesn't have a model with the relevant trait)
    // Posts + comments + authors (3 tables) with table manager
    $tableCount = $manager === TraitRLSManager::class ? 2 : 3;

    $policyCount = fn () => count(DB::select('SELECT * FROM pg_policies'));
    expect($policyCount())->toBe(0);

    pest()->artisan('tenants:rls');
    expect($policyCount())->toBe($tableCount);

    $policies = DB::select('SELECT * FROM pg_policies');

    DB::statement("DROP POLICY {$policies[0]->policyname} ON {$policies[0]->tablename}");
    expect($policyCount())->toBe($tableCount - 1); // one deleted

    pest()->artisan('tenants:rls');

    // back to original count
    expect($policyCount())->toBe($tableCount);
    expect(DB::select('SELECT * FROM pg_policies'))->toHaveCount(count($policies));
})->with([
    TableRLSManager::class,
    TraitRLSManager::class,
]);

test('rls command recreates outdated policies', function (string $manager) {
    config(['tenancy.rls.manager' => $manager]);

    // "test" being an outdated hash
    DB::statement('CREATE POLICY posts_rls_policy_test ON posts');

    pest()->artisan('tenants:rls');

    expect(DB::selectOne("SELECT policyname FROM pg_policies WHERE tablename = 'posts'")->policyname)
        ->toStartWith('posts_rls_policy_')
        ->not()->toBe('posts_rls_policy_test');
})->with([
    TableRLSManager::class,
    TraitRLSManager::class,
]);

test('rls command recreates policies if the force option is passed', function (string $manager) {
    config(['tenancy.rls.manager' => $manager]);

    /** @var CreateUserWithRLSPolicies $policyCreationCommand */
    $policyCreationCommand = app(CreateUserWithRLSPolicies::class);

    $hash = $policyCreationCommand->hashPolicy(app(config('tenancy.rls.manager'))->generateQueries()['posts'])[0];
    $policyNameWithHash = "posts_rls_policy_{$hash}";

    DB::enableQueryLog();
    DB::statement($policyCreationQuery = "CREATE POLICY {$policyNameWithHash} ON posts");

    pest()->artisan('tenants:rls', ['--force' => true]);

    $postsPolicyCreationQueries = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $query) => str($query)->contains($policyCreationQuery));

    expect($postsPolicyCreationQueries)->toHaveCount(2);
})->with([
    TableRLSManager::class,
    TraitRLSManager::class,
]);

test('queries will stop working when the tenant session variable is not set', function(string $manager) {
    config(['tenancy.rls.manager' => $manager]);

    $sessionVariableName = config('tenancy.rls.session_variable_name');

    $tenant = Tenant::create();

    pest()->artisan('tenants:rls');

    tenancy()->initialize($tenant);

    // The session variable is set correctly
    // Creating a record for the current tenant should work
    $authorId = DB::selectOne(<<<SQL
        INSERT INTO authors (name, tenant_id)
        VALUES ('author1', ?)
        RETURNING id
    SQL, [$tenant->id])->id;

    expect(fn () => DB::insert(<<<SQL
        INSERT INTO posts (text, tenant_id, author_id)
        VALUES ('post1', ?, ?)
    SQL, [$tenant->id, $authorId]))->not()->toThrow(Exception::class);

    DB::statement("RESET {$sessionVariableName}");

    // Throws RLS violation exception
    // The session variable is not set to the current tenant key
    expect(fn () => DB::insert(<<<SQL
        INSERT INTO posts (text, tenant_id, author_id)
        VALUES ('post2', ?, ?)
    SQL, [$tenant->id, $authorId]))->toThrow(QueryException::class);
})->with([
    TableRLSManager::class,
    TraitRLSManager::class,
]);
