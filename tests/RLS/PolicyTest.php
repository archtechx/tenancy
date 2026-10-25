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
use Stancl\Tenancy\Tenancy;
use function Stancl\Tenancy\Tests\pest;

beforeEach(function () {
    CreateUserWithRLSPolicies::$forceRls = true;
    CreateUserWithRLSPolicies::$disableRlsOnTablesWithoutPolicies = true;
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

afterEach(function () {
    CreateUserWithRLSPolicies::$forceRls = true;
    CreateUserWithRLSPolicies::$disableRlsOnTablesWithoutPolicies = true;
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

    pest()->artisan('tenants:rls --force');

    $postsPolicyCreationQueries = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $query) => str($query)->contains($policyCreationQuery));

    expect($postsPolicyCreationQueries)->toHaveCount(2);
})->with([
    TableRLSManager::class,
    TraitRLSManager::class,
]);

test('dropRLSPolicies only drops RLS policies', function () {
    DB::statement('CREATE POLICY "comments_dummy_rls_policy" ON comments USING (true)');
    DB::statement('CREATE POLICY "comments_foo_policy" ON comments USING (true)'); // non-RLS policy

    $policyCount = fn () => count(DB::select("SELECT policyname FROM pg_policies WHERE tablename = 'comments'"));

    expect($policyCount())->toBe(2);

    $removed = Tenancy::dropRLSPolicies('comments');

    expect($removed)->toBe(1);

    // Only the non-RLS policy remains
    expect($policyCount())->toBe(1);
});

test('queries will stop working when the tenant session variable is not set', function(string $manager, bool $forceRls) {
    CreateUserWithRLSPolicies::$forceRls = $forceRls;

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
})->with([TableRLSManager::class, TraitRLSManager::class])->with([true, false]);

test('rls command with the force option updates the forced state of rls on tables when forceRls changes', function (string $manager) {
    config(['tenancy.rls.manager' => $manager]);

    $tables = array_keys(app($manager)->generateQueries());
    $rlsState = fn () => collect($tables)->mapWithKeys(fn (string $table) => [
        $table => (array) DB::selectOne('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = ?::regclass', [$table]),
    ])->all();

    CreateUserWithRLSPolicies::$forceRls = true;
    pest()->artisan('tenants:rls');

    foreach ($rlsState() as $state) {
        expect($state)->toBe(['relrowsecurity' => true, 'relforcerowsecurity' => true]);
    }

    // The policies are up to date, so the force option is needed to un-force the tables
    CreateUserWithRLSPolicies::$forceRls = false;
    pest()->artisan('tenants:rls', ['--force' => true]);

    foreach ($rlsState() as $state) {
        expect($state)->toBe(['relrowsecurity' => true, 'relforcerowsecurity' => false]);
    }

    // Enabling forceRls again should force the tables again
    CreateUserWithRLSPolicies::$forceRls = true;
    pest()->artisan('tenants:rls', ['--force' => true]);

    foreach ($rlsState() as $state) {
        expect($state)->toBe(['relrowsecurity' => true, 'relforcerowsecurity' => true]);
    }
})->with([
    TableRLSManager::class,
    TraitRLSManager::class,
]);

test('rls command disables rls on tables whose zombie policy was their last policy', function () {
    Schema::create('foo', fn (Blueprint $table) => $table->id());
    DB::table('foo')->insert([['id' => 1], ['id' => 2]]);

    // foo isn't related to the tenants table, so this policy is a zombie
    DB::statement('CREATE POLICY foo_rls_policy_abc123 ON foo USING (true)');
    DB::statement('ALTER TABLE foo ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY');

    $tenant = Tenant::create();
    pest()->artisan('tenants:rls');

    expect((array) DB::selectOne("SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = 'foo'::regclass"))
        ->toBe(['relrowsecurity' => false, 'relforcerowsecurity' => false]);

    tenancy()->initialize($tenant);

    // With RLS left enabled and no policies, the RLS user would see no rows
    expect(DB::table('foo')->pluck('id')->sort()->values()->all())->toBe([1, 2]);
});

test('rls command keeps rls enabled on tables with other policies after dropping a zombie policy', function () {
    Schema::create('foo', fn (Blueprint $table) => $table->id());
    DB::table('foo')->insert([['id' => 1], ['id' => 2]]);

    // foo isn't related to the tenants table, so this policy is a zombie
    DB::statement('CREATE POLICY foo_rls_policy_abc123 ON foo USING (true)');
    DB::statement('CREATE POLICY foo_custom_policy ON foo USING (id = 1)');
    DB::statement('ALTER TABLE foo ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY');

    $tenant = Tenant::create();
    pest()->artisan('tenants:rls');

    expect(DB::select("SELECT policyname FROM pg_policies WHERE tablename = 'foo'"))->toHaveCount(1);

    tenancy()->initialize($tenant);

    // The user-defined policy still applies
    expect(DB::table('foo')->pluck('id')->all())->toBe([1]);
});

test('rls command disables rls on tables that stopped being scoped after their policies were dropped', function () {
    config(['tenancy.rls.manager' => TableRLSManager::class]);

    $tenant = Tenant::create();
    $authorId = DB::table('authors')->insertGetId(['name' => 'author', 'tenant_id' => $tenant->id]);
    $postId = DB::table('posts')->insertGetId(['text' => 'post', 'author_id' => $authorId]);
    DB::table('comments')->insert(['text' => 'comment', 'post_id' => $postId]);

    pest()->artisan('tenants:rls');

    // Stop scoping comments the way a migration would
    Tenancy::dropRLSPolicies('comments');
    Schema::table('comments', fn (Blueprint $table) => $table->unsignedBigInteger('post_id')->comment('no-rls')->change());

    pest()->artisan('tenants:rls');

    expect((array) DB::selectOne("SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = 'comments'::regclass"))
        ->toBe(['relrowsecurity' => false, 'relforcerowsecurity' => false]);

    // comments aren't scoped anymore, so another tenant can see the comment too
    tenancy()->initialize(Tenant::create());

    expect(DB::table('comments')->count())->toBe(1);
});

test('rls command keeps rls enabled on tables without policies if disableRlsOnTablesWithoutPolicies is false', function () {
    CreateUserWithRLSPolicies::$disableRlsOnTablesWithoutPolicies = false;

    Schema::create('foo', fn (Blueprint $table) => $table->id());
    DB::statement('ALTER TABLE foo ENABLE ROW LEVEL SECURITY');

    pest()->artisan('tenants:rls');

    expect(DB::selectOne("SELECT relrowsecurity FROM pg_class WHERE oid = 'foo'::regclass")->relrowsecurity)->toBeTrue();
});

test('rls policies in other schemas are ignored', function () {
    config(['tenancy.rls.manager' => TableRLSManager::class]);

    $postsPolicy = 'posts_rls_policy_' . app(CreateUserWithRLSPolicies::class)->hashPolicy(app(TableRLSManager::class)->generateQueries()['posts'])[0];

    // Tables with the same names as central tables, with policies named like ours
    DB::statement('DROP SCHEMA IF EXISTS other CASCADE');
    DB::statement('CREATE SCHEMA other');
    DB::statement('CREATE TABLE other.posts (id int)');
    DB::statement('CREATE TABLE other.foo (id int)');
    DB::statement("CREATE POLICY {$postsPolicy} ON other.posts USING (true)");
    DB::statement('CREATE POLICY foo_rls_policy ON other.foo USING (true)');

    try {
        pest()->artisan('tenants:rls');

        // The central posts table gets its policy even though other.posts has a policy with the same name
        expect(DB::select("SELECT 1 FROM pg_policies WHERE schemaname = 'public' AND tablename = 'posts'"))->toHaveCount(1);

        expect(Tenancy::dropRLSPolicies('posts'))->toBe(1);

        expect(DB::select("SELECT 1 FROM pg_policies WHERE schemaname = 'other'"))->toHaveCount(2);
    } finally {
        DB::statement('DROP SCHEMA other CASCADE');
    }
});
