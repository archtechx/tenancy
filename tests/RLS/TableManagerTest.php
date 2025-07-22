<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Stancl\Tenancy\Events\TenancyEnded;
use Illuminate\Database\Schema\Blueprint;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\ForeignIdColumnDefinition;
use Stancl\Tenancy\Commands\CreateUserWithRLSPolicies;
use Stancl\Tenancy\RLS\PolicyManagers\TableRLSManager;
use Stancl\Tenancy\Bootstrappers\PostgresRLSBootstrapper;
use Stancl\Tenancy\Database\Exceptions\RecursiveRelationshipException;
use function Stancl\Tenancy\Tests\pest;
use Stancl\Tenancy\RLS\Exceptions\RLSCommentConstraintException;

beforeEach(function () {
    CreateUserWithRLSPolicies::$forceRls = true;
    TableRLSManager::$scopeByDefault = true;

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);

    DB::purge($centralConnection = config('tenancy.database.central_connection'));
    config(['database.connections.' . $centralConnection => config('database.connections.pgsql')]);
    config(['tenancy.models.tenant_key_column' => 'tenant_id']);
    config(['tenancy.models.tenant' => Tenant::class]);
    config(['tenancy.rls.manager' => TableRLSManager::class]);
    config(['tenancy.rls.user.username' => 'username']);
    config(['tenancy.rls.user.password' => 'password']);
    config(['tenancy.bootstrappers' => [PostgresRLSBootstrapper::class]]);

    pest()->artisan('migrate:fresh', [
        '--force' => true,
        '--path' => __DIR__ . '/../../assets/migrations',
        '--realpath' => true,
    ]);

    Schema::create('authors', function (Blueprint $table) {
        $table->id();
        $table->string('name');

        $table->string('tenant_id')->comment('rls');
        $table->foreign('tenant_id')->references('id')->on('tenants')->onUpdate('cascade')->onDelete('cascade');

        $table->timestamps();
    });

    Schema::create('categories', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('tenant_id')->comment('no-rls'); // not scoped
        $table->foreign('tenant_id')->references('id')->on('tenants')->onUpdate('cascade');

        $table->timestamps();
    });

    Schema::create('posts', function (Blueprint $table) {
        $table->id();
        $table->string('text');

        // Multiple foreign keys to test if the table manager generates the paths correctly

        // Leads to the tenants table, BUT is nullable
        $table->string('tenant_id')->nullable()->comment('rls');
        $table->foreign('tenant_id')->references('id')->on('tenants')->onUpdate('cascade')->onDelete('cascade');

        // Leads to the tenants table – shortest path (because the tenant key column is nullable – excluded when choosing the shortest path)
        $table->foreignId('author_id')->comment('rls')->constrained('authors');

        // Doesn't lead to the tenants table because of a no-rls comment further down the path – should get excluded from paths entirely
        $table->foreignId('category_id')->comment('rls')->nullable()->constrained('categories');

        $table->timestamps();
    });

    Schema::create('comments', function (Blueprint $table) {
        $table->id();
        $table->string('text');
        $table->foreignId('post_id')->comment('rls')->constrained('posts')->onUpdate('cascade')->onDelete('cascade');

        $table->timestamps();
    });

    // Related to tenants table only through a no-rls path
    // Exists to see if the manager correctly excludes it from the paths
    Schema::create('reactions', function (Blueprint $table) {
        $table->id();
        $table->boolean('like')->default(true);
        $table->foreignId('comment_id')->comment('no-rls')->constrained('comments')->onUpdate('cascade')->onDelete('cascade');

        $table->timestamps();
    });

    // Not related to the tenants table in any way
    // Exists to check that the manager doesn't generate paths for models not related to the tenants table
    Schema::create('articles', function (Blueprint $table) {
        $table->id();
        $table->string('text');
        $table->timestamps();
    });
});

afterEach(function () {
    CreateUserWithRLSPolicies::$forceRls = true;
});

test('correct rls policies get created with the correct hash using table manager', function() {
    $manager = app(config('tenancy.rls.manager'));

    $tables = [
        'authors',
        'posts',
        'comments',
        // The following tables will get completely excluded from policy generation
        // Because they are only related to the tenants table by paths using the 'no-rls' comment
        // 'reactions',
        // 'categories',
    ];

    $getRLSPolicies = fn () => DB::select('SELECT policyname, tablename FROM pg_policies');
    $getRLSTables = fn () => collect($tables)->map(fn ($table) => DB::select('SELECT relname, relforcerowsecurity FROM pg_class WHERE oid = ?::regclass', [$table]))->collapse();

    // Drop all existing policies to check if the command creates policies for multiple tables
    foreach ($getRLSPolicies() as $policy) {
        DB::statement("DROP POLICY IF EXISTS {$policy->policyname} ON {$policy->tablename}");
    }

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

test('queries are correctly scoped using RLS', function (
    bool $forceRls,
    bool $commentConstraint,
) {
    CreateUserWithRLSPolicies::$forceRls = $forceRls;

    // 3-levels deep relationship
    Schema::create('notes', function (Blueprint $table) use ($commentConstraint) {
        $table->id();
        $table->string('text')->default('foo');
        // no rls comment needed, $scopeByDefault is set to true
        if ($commentConstraint) {
            $table->foreignId('comment_id')->comment('rls comments.id');
        } else {
            $table->foreignId('comment_id')->constrained('comments');
        }
        $table->timestamps();
    });

    // Create RLS policies for tables and the tenant user
    pest()->artisan('tenants:rls');

    // Create two tenants
    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();

    // Create posts and comments for both tenants
    tenancy()->initialize($tenant1);

    $post1 = Post::create([
        'text' => 'first post',
        'tenant_id' => $tenant1->id,
        'author_id' => Author::create(['name' => 'author1', 'tenant_id' => $tenant1->id])->id,
        'category_id' => Category::create(['name' => 'category1', 'tenant_id' => $tenant1->id])->id,
    ]);

    $post1Comment = Comment::create(['text' => 'first comment', 'post_id' => $post1->id]);

    $post1Comment->notes()->create(['text' => 'foo']);

    tenancy()->initialize($tenant2);

    $post2 = Post::create([
        'text' => 'second post',
        'tenant_id' => $tenant2->id,
        'author_id' => Author::create(['name' => 'author2', 'tenant_id' => $tenant2->id])->id,
        'category_id' => Category::create(['name' => 'category2', 'tenant_id' => $tenant2->id])->id
    ]);

    $post2Comment = Comment::create(['text' => 'second comment', 'post_id' => $post2->id]);

    $post2Comment->notes()->create(['text' => 'bar']);

    tenancy()->initialize($tenant1);

    expect(Post::all()->pluck('text'))
        ->toHaveCount(1)
        ->toContain($post1->text)
        ->not()->toContain($post2->text)
        ->toEqual(Post::withoutGlobalScopes()->get()->pluck('text'));

    expect(Comment::all()->pluck('text'))
        ->toHaveCount(1)
        ->toContain($post1Comment->text)
        ->not()->toContain($post2Comment->text)
        ->toEqual(Comment::withoutGlobalScopes()->get()->pluck('text'));

    expect(Note::all()->pluck('text'))
        ->toHaveCount(1)
        ->toContain('foo') // $note1->text
        ->not()->toContain('bar') // $note2->text
        ->toEqual(Note::withoutGlobalScopes()->get()->pluck('text'));

    tenancy()->end();

    expect(Post::all()->pluck('text'))
        ->toHaveCount(2)
        ->toContain($post1->text)
        ->toContain($post2->text);

    expect(Comment::all()->pluck('text'))
        ->toHaveCount(2)
        ->toContain($post1Comment->text)
        ->toContain($post2Comment->text);

    expect(Note::all()->pluck('text'))
        ->toHaveCount(2)
        ->toContain('foo')
        ->toContain('bar');

    tenancy()->initialize($tenant2);

    expect(Post::all()->pluck('text'))
        ->toHaveCount(1)
        ->toContain($post2->text)
        ->not()->toContain($post1->text)
        ->toEqual(Post::withoutGlobalScopes()->get()->pluck('text'));

    expect(Comment::all()->pluck('text'))
        ->toHaveCount(1)
        ->toContain($post2Comment->text)
        ->not()->toContain($post1Comment->text)
        ->toEqual(Comment::withoutGlobalScopes()->get()->pluck('text'));

    expect(Note::all()->pluck('text'))
        ->toHaveCount(1)
        ->toContain('bar')
        ->not()->toContain('foo')
        ->toEqual(Note::withoutGlobalScopes()->get()->pluck('text'));

    // Test that RLS policies protect tenants from other tenant's direct queries
    // Try updating records of the other tenant – should have no effect
    DB::statement("UPDATE posts SET text = 'updated' WHERE id = {$post1->id}");
    DB::statement("UPDATE comments SET text = 'updated' WHERE id = {$post1Comment->id}");
    DB::statement("UPDATE notes SET text = 'updated'"); // should only update the current tenant's comments

    // Still in tenant2
    expect(Note::all()->pluck('text'))
        ->toContain('updated'); // query with no WHERE updated the current tenant's comments
    expect(Post::all()->pluck('text'))
        ->toContain('second post'); // query with a where targeting another user's post had no effect on the current tenant's posts
    expect(Comment::all()->pluck('text'))
        ->toContain('second comment'); // query with a where targeting another user's post had no effect on the current tenant's posts

    tenancy()->initialize($tenant1);

    expect(Post::all()->pluck('text'))
        ->toContain($post1->text)
        ->not()->toContain($post2->text)
        ->not()->toContain('updated') // Text of tenant records wasn't changed to 'updated'
        ->toEqual(Post::withoutGlobalScopes()->get()->pluck('text'));

    expect(Comment::all()->pluck('text'))
        ->toContain($post1Comment->text)
        ->not()->toContain($post2Comment->text)
        ->not()->toContain('updated')
        ->toEqual(Comment::withoutGlobalScopes()->get()->pluck('text'));

    expect(Note::all()->pluck('text'))
        ->toContain('foo')
        ->not()->toContain('bar')
        ->not()->toContain('updated')
        ->toEqual(Note::withoutGlobalScopes()->get()->pluck('text'));

    // Try deleting second tenant's records – should have no effect
    DB::statement("DELETE FROM posts WHERE id = {$post2->id}");
    DB::statement("DELETE FROM comments WHERE id = {$post2Comment->id}");
    DB::statement("DELETE FROM notes");

    // Still in tenant1
    expect(Post::all())->toHaveCount(1); // query with a where targeting another tenant's post had no effect on the current tenant's posts
    expect(Comment::all())->toHaveCount(1); // query with a where targeting another tenant's post had no effect on the current tenant's posts
    expect(Note::all())->toHaveCount(0); // query with no WHERE updated the current tenant's comments

    tenancy()->initialize($tenant2);

    // Records weren't deleted by the first tenant
    expect(Post::count())->toBe(1);
    expect(Comment::count())->toBe(1);
    expect(Note::count())->toBe(1);

    // Directly inserting records to other tenant's tables should fail (insufficient privilege error – new row violates row-level security policy)
    expect(fn () => DB::statement("INSERT INTO posts (text, author_id, category_id, tenant_id) VALUES ('third post', 1, 1, '{$tenant1->id}')"))
        ->toThrow(QueryException::class);

    expect(fn () => DB::statement("INSERT INTO comments (text, post_id) VALUES ('third comment', {$post1->id})"))
        ->toThrow(QueryException::class);

    expect(fn () => DB::statement("INSERT INTO notes (text, comment_id) VALUES ('baz', {$post1Comment->id})"))
        ->toThrow(QueryException::class);
})->with(['forceRls is true' => true, 'forceRls is false' => false])
    ->with(['comment constraint' => true, 'foreign key constraint' => false]);

test('table rls manager generates shortest paths that lead to the tenants table correctly', function (bool $scopeByDefault) {
    TableRLSManager::$scopeByDefault = $scopeByDefault;

    // Only related to the tenants table through nullable columns (directly through tenant_id and indirectly through post_id)
    Schema::create('ratings', function (Blueprint $table) {
        $table->id();

        $table->foreignId('post_id')->nullable()->comment('rls')->constrained();

        // No 'rls' comment – should get excluded from path generation when using explicit scoping
        $table->string('tenant_id')->nullable();
        $table->foreign('tenant_id')->references('id')->on('tenants')->onUpdate('cascade')->onDelete('cascade');

        $table->timestamps();
    });

    /** @var TableRLSManager $manager */
    $manager = app(TableRLSManager::class);

    $expectedShortestPaths = [
        'authors' => [
            [
                'localColumn' => 'tenant_id',
                'foreignTable' => 'tenants',
                'foreignColumn' => 'id',
            ],
        ],
        'posts' => [
            [
                'localColumn' => 'author_id',
                'foreignTable' => 'authors',
                'foreignColumn' => 'id',
            ],
            [
                'localColumn' => 'tenant_id',
                'foreignTable' => 'tenants',
                'foreignColumn' => 'id',
            ],
        ],
        'comments' => [
            [
                'localColumn' => 'post_id',
                'foreignTable' => 'posts',
                'foreignColumn' => 'id',
            ],
            [
                'localColumn' => 'author_id',
                'foreignTable' => 'authors',
                'foreignColumn' => 'id',
            ],
            [
                'localColumn' => 'tenant_id',
                'foreignTable' => 'tenants',
                'foreignColumn' => 'id',
            ],
        ],
        // When scoping by default is enabled (implicit scoping),
        // the shortest path from the ratings table leads directly through tenant_id.
        // When scoping by default is disabled (explicit scoping),
        // the shortest path leads through post_id.
        'ratings' => $scopeByDefault ? [
            [
                'localColumn' => 'tenant_id',
                'foreignTable' => 'tenants',
                'foreignColumn' => 'id',
            ],
        ] : [
            [
                'localColumn' => 'post_id',
                'foreignTable' => 'posts',
                'foreignColumn' => 'id',
            ],
            [
                'localColumn' => 'author_id',
                'foreignTable' => 'authors',
                'foreignColumn' => 'id',
            ],
            [
                'localColumn' => 'tenant_id',
                'foreignTable' => 'tenants',
                'foreignColumn' => 'id',
            ],
        ],
        // Articles table is ignored because it's not related to the tenant table in any way
        // Reactions table is ignored because of the 'no-rls' comment on the comment_id column
        // Categories table is ignored because of the 'no-rls' comment on the tenant_id column
    ];

    expect($manager->shortestPaths())->toEqual($expectedShortestPaths);

    // Add non-nullable comment_id comment constraint
    Schema::table('ratings', function (Blueprint $table) {
        $table->string('comment_id')->comment('rls comments.id');

        // Nullable constraint with a non-RLS comment.
        // Skipped when scopeByDefault is false,
        // not ignored when scopeByDefault is true, but still,
        // not preferred since comment_id is valid and non-nullable.
        $table->foreignId('author_id')->nullable()->comment('random comment')->constrained('authors');
    });

    // Non-nullable paths are preferred over nullable paths
    $expectedShortestPaths['ratings'] = [
        [
            'localColumn' => 'comment_id',
            'foreignTable' => 'comments',
            'foreignColumn' => 'id',
        ],
        [
            'localColumn' => 'post_id',
            'foreignTable' => 'posts',
            'foreignColumn' => 'id',
        ],
        [
            // Importantly, the best path goes through authors
            // since ratings -> posts is nullable, as well as
            // posts -> tenants directly (without going through
            // authors first).
            'localColumn' => 'author_id',
            'foreignTable' => 'authors',
            'foreignColumn' => 'id',
        ],
        [
            'localColumn' => 'tenant_id',
            'foreignTable' => 'tenants',
            'foreignColumn' => 'id',
        ],
    ];

    // The shortest paths should now include a path for the ratings table
    // that leads through comment_id instead of tenant_id since comment_id
    // is not nullable (and therefore preferable) unlike path_id or tenant_id
    // even if the latter paths are shorter.
    expect($manager->shortestPaths())->toEqual($expectedShortestPaths);
})->with([true, false]);

// https://github.com/archtechx/tenancy/pull/1293
test('forceRls prevents even the table owner from querying his own tables if he doesnt have a BYPASSRLS permission', function (bool $forceRls) {
    CreateUserWithRLSPolicies::$forceRls = $forceRls;

    // Drop all tables created in beforeEach
    DB::statement("DROP TABLE authors, categories, posts, comments, reactions, articles;");

    // Create a new user so we have full control over the permissions.
    // We explicitly set bypassRls to false.
    [$username, $password] = createPostgresUser('administrator', bypassRls: false);

    config(['database.connections.central' => array_merge(config('database.connections.pgsql'), [
        'username' => $username,
        'password' => $password,
    ])]);

    DB::reconnect();

    // This table is owned by the newly created 'administrator' user
    Schema::create('orders', function (Blueprint $table) {
        $table->id();
        $table->string('name');

        $table->string('tenant_id')->comment('rls');
        $table->foreign('tenant_id')->references('id')->on('tenants')->onUpdate('cascade')->onDelete('cascade');

        $table->timestamps();
    });

    $tenant1 = Tenant::create();

    // Create RLS policy for the orders table
    pest()->artisan('tenants:rls');

    $tenant1->run(fn () => Order::create(['name' => 'order1', 'tenant_id' => $tenant1->id]));

    // We are still using the 'administrator' user - owner of the orders table

    if ($forceRls) {
        // RLS is forced, so by default, not even the table owner should be able to query the table protected by the RLS policy.
        // The RLS policy is not being bypassed, 'unrecognized configuration parameter' means
        // that the my.current_tenant session variable isn't set -- the RLS policy is *still* being enforced.
        expect(fn () => Order::first())->toThrow(QueryException::class, 'unrecognized configuration parameter "my.current_tenant"');
    } else {
        // RLS is not forced, so the table owner should be able to query the table, bypassing the RLS policy
        expect(Order::first())->not()->toBeNull();
    }
})->with([true, false]);

test('users with BYPASSRLS privilege can bypass RLS regardless of forceRls setting', function (bool $forceRls, bool $bypassRls) {
    CreateUserWithRLSPolicies::$forceRls = $forceRls;

    // Drop all tables created in beforeEach
    DB::statement("DROP TABLE authors, categories, posts, comments, reactions, articles;");

    // Create a new user so we have control over his BYPASSRLS permission
    // and use that as the new central connection user
    [$username, $password] = createPostgresUser('administrator', 'password', $bypassRls);

    config(['database.connections.central' => array_merge(config('database.connections.pgsql'), [
        'username' => $username,
        'password' => $password,
    ])]);

    DB::reconnect();

    Schema::create('orders', function (Blueprint $table) {
        $table->id();
        $table->string('name');

        $table->string('tenant_id')->comment('rls');
        $table->foreign('tenant_id')->references('id')->on('tenants')->onUpdate('cascade')->onDelete('cascade');

        $table->timestamps();
    });

    $tenant1 = Tenant::create();

    // Create RLS policy for the orders table
    pest()->artisan('tenants:rls');

    $tenant1->run(fn () => Order::create(['name' => 'order1', 'tenant_id' => $tenant1->id]));

    // We are still using the 'administrator' user

    if ($bypassRls) {
        // Users with BYPASSRLS can always query tables regardless of forceRls setting
        expect(Order::count())->toBe(1);
        expect(Order::first()->name)->toBe('order1');
    } else {
        // Users without BYPASSRLS are subject to RLS policies even if they're table owners when forceRls is true
        // OR they can bypass as table owners (when forceRls=false)
        if ($forceRls) {
            // Even table owners need session variable -- this means RLS was NOT bypassed
            expect(fn () => Order::first())->toThrow(QueryException::class, 'unrecognized configuration parameter "my.current_tenant"');
        } else {
            // Table owners can bypass RLS automatically when forceRls is false
            expect(Order::count())->toBe(1);
            expect(Order::first()->name)->toBe('order1');
        }
    }
})->with([true, false])->with([true, false]);

test('table rls manager generates queries correctly', function() {
    expect(array_values(app(TableRLSManager::class)->generateQueries()))->toEqualCanonicalizing([
        <<<SQL
        CREATE POLICY authors_rls_policy ON authors USING (
            tenant_id::text = current_setting('my.current_tenant')
        );
        SQL,
        <<<SQL
        CREATE POLICY posts_rls_policy ON posts USING (
            author_id IN (
                SELECT id
                FROM authors
                WHERE tenant_id::text = current_setting('my.current_tenant')
            )
        );
        SQL,
        <<<SQL
        CREATE POLICY comments_rls_policy ON comments USING (
            post_id IN (
                SELECT id
                FROM posts
                WHERE author_id IN (
                    SELECT id
                    FROM authors
                    WHERE tenant_id::text = current_setting('my.current_tenant')
                )
            )
        );
        SQL,
    ]);

    // Query generation works when passing custom paths
    $paths = [
        'primaries' => [
            [
                'localColumn' => 'tenant_id',
                'foreignTable' => 'tenants',
                'foreignColumn' => 'id',
            ],
        ],
        'secondaries' => [
            [
                'localColumn' => 'primary_id',
                'foreignTable' => 'primaries',
                'foreignColumn' => 'id',
            ],
            [
                'localColumn' => 'tenant_id',
                'foreignTable' => 'tenants',
                'foreignColumn' => 'id',
            ],
        ],
        'foo' => [
            [
                'localColumn' => 'secondary_id',
                'foreignTable' => 'secondaries',
                'foreignColumn' => 'id',
            ],
            [
                'localColumn' => 'primary_id',
                'foreignTable' => 'primaries',
                'foreignColumn' => 'id',
            ],
            [
                'localColumn' => 'tenant_id',
                'foreignTable' => 'tenants',
                'foreignColumn' => 'id',
            ],
        ],
    ];

    expect(app(TableRLSManager::class)->generateQueries($paths))->toContain(
        <<<SQL
        CREATE POLICY primaries_rls_policy ON primaries USING (
            tenant_id::text = current_setting('my.current_tenant')
        );
        SQL,
        <<<SQL
        CREATE POLICY secondaries_rls_policy ON secondaries USING (
            primary_id IN (
                SELECT id
                FROM primaries
                WHERE tenant_id::text = current_setting('my.current_tenant')
            )
        );
        SQL,
        <<<SQL
        CREATE POLICY foo_rls_policy ON foo USING (
            secondary_id IN (
                SELECT id
                FROM secondaries
                WHERE primary_id IN (
                    SELECT id
                    FROM primaries
                    WHERE tenant_id::text = current_setting('my.current_tenant')
                )
            )
        );
        SQL,
    );
});

test('table manager throws an exception when the only available paths lead through recursive relationships', function (bool $useCommentConstraints) {
    // We test recursive relations using both foreign key constraints and comment constraints
    $makeConstraint = function (ForeignIdColumnDefinition $relation, $table, $column) use ($useCommentConstraints) {
        if ($useCommentConstraints) {
            $relation->comment("rls $table.$column");
        } else {
            $relation->constrained($table, $column);
        }
    };

    Schema::create('recursive_posts', function (Blueprint $table) {
        $table->id();
    });

    Schema::create('recursive_comments', function (Blueprint $table) {
        $table->id();
    });

    Schema::table('recursive_posts', function (Blueprint $table) use ($makeConstraint) {
        $makeConstraint($table->foreignId('highlighted_comment_id')->nullable(), 'recursive_comments', 'id');
    });

    Schema::table('recursive_comments', function (Blueprint $table) use ($makeConstraint) {
        $makeConstraint($table->foreignId('recursive_post_id'), 'recursive_posts', 'id');
    });

    expect(fn () => app(TableRLSManager::class)->shortestPaths())->toThrow(RecursiveRelationshipException::class);

    Schema::table('recursive_comments', function (Blueprint $table) use ($makeConstraint, $useCommentConstraints) {
        // Add another recursive relationship to demonstrate a more complex case
        $makeConstraint($table->foreignId('related_post_id'), 'recursive_posts', 'id');

        // Add a foreign key to the current table (= self-referencing constraint)
        $makeConstraint($table->foreignId('parent_comment_id'), 'recursive_comments', 'id');

        // Add tenant_id to break the recursion - RecursiveRelationshipException should not be thrown
        // We cannot use $makeConstraint() here since tenant_id is a string column
        if ($useCommentConstraints) {
            $table->string('tenant_id')->comment('rls tenants.id');
        } else {
            $table->string('tenant_id')->comment('rls')->nullable();
            $table->foreign('tenant_id')->references('id')->on('tenants')->onUpdate('cascade')->onDelete('cascade');
        }
    });

    // Doesn't throw an exception anymore
    $shortestPaths = app(TableRLSManager::class)->shortestPaths();

    // Generated paths include both the recursive_posts and the recursive_comments tables
    // because they actually lead to the tenants table now.
    //
    // recursive_comments has a direct path to tenants, recursive_posts has a path
    // to tenants through recursive_comments
    expect(array_keys($shortestPaths))->toContain('recursive_posts', 'recursive_comments');
})->with([true, false]);

test('table manager ignores recursive relationship if the foreign key responsible for the recursion has no-rls comment', function() {
    Schema::create('recursive_posts', function (Blueprint $table) {
        $table->id();
        $table->foreignId('highlighted_comment_id')->nullable()->comment('no-rls')->constrained('comments');
    });

    // Add a foreign key constraint to the comments table to introduce a recursive relationship
    // Note that the comments table still has the post_id foreign key that leads to the tenants table
    Schema::table('comments', function (Blueprint $table) {
        $table->foreignId('recursive_post_id')->comment('rls')->constrained('recursive_posts');
    });

    // No exception thrown because
    // the highlighted_comment_id foreign key has a no-rls comment
    $shortestPaths = app(TableRLSManager::class)->shortestPaths();

    expect(array_keys($shortestPaths))
        ->toContain('posts', 'comments')
        // Shortest paths do not include the recursive_posts table
        // because it has a 'no-rls' comment on its only foreign key
        ->not()->toContain('recursive_posts');
});

test('table manager can generate paths leading through comment constraint columns', function() {
    // Drop extra tables created in beforeEach
    Schema::dropIfExists('reactions');
    Schema::dropIfExists('comments');
    Schema::dropIfExists('posts');
    Schema::dropIfExists('authors');

    Schema::create('non_constrained_users', function (Blueprint $table) {
        $table->id();
        $table->string('tenant_id')->comment('rls tenants.id'); // Comment constraint
    });

    Schema::create('non_constrained_posts', function (Blueprint $table) {
        $table->id();
        $table->foreignId('author_id')->comment('rls non_constrained_users.id'); // Comment constraint
    });

    /** @var TableRLSManager $manager */
    $manager = app(TableRLSManager::class);

    $expectedPaths = [
        'non_constrained_posts' => [
            [
                'localColumn' => 'author_id',
                'foreignTable' => 'non_constrained_users',
                'foreignColumn' => 'id',
            ],
            [
                'localColumn' => 'tenant_id',
                'foreignTable' => 'tenants',
                'foreignColumn' => 'id',
            ],
        ],
        'non_constrained_users' => [
            [
                'localColumn' => 'tenant_id',
                'foreignTable' => 'tenants',
                'foreignColumn' => 'id',
            ],
        ],
    ];

    expect($manager->shortestPaths())->toEqual($expectedPaths);
});

test('table manager throws an exception when comment constraint is incorrect', function(string $comment, string $exceptionMessage) {
    Schema::create('non_constrained_users', function (Blueprint $table) use ($comment) {
        $table->id();
        $table->string('tenant_id')->comment($comment); // Invalid comment constraint
    });

    /** @var TableRLSManager $manager */
    $manager = app(TableRLSManager::class);

    expect(fn () => $manager->shortestPaths())->toThrow(
        RLSCommentConstraintException::class,
        $exceptionMessage
    );
})->with([
    ['rls ', 'Malformed comment constraint on non_constrained_users'], // Missing table.column
    ['rls tenants', 'Malformed comment constraint on non_constrained_users'], // Missing column part
    ['rls tenants.', 'Malformed comment constraint on non_constrained_users'], // Missing column part
    ['rls .id', 'Malformed comment constraint on non_constrained_users'], // Missing table part
    ['rls tenants.foreign.id', 'Malformed comment constraint on non_constrained_users'], // Too many parts
    ['rls nonexistent-table.id', 'references non-existent table'],
    ['rls tenants.nonexistent-column', 'references non-existent column'],
]);

function createPostgresUser(string $username, string $password = 'password', bool $bypassRls = false): array
{
    try {
        DB::statement("DROP OWNED BY {$username};");
    } catch (\Throwable) {}

    DB::statement("DROP USER IF EXISTS {$username};");

    DB::statement("CREATE USER {$username} WITH ENCRYPTED PASSWORD '{$password}'");
    DB::statement("ALTER USER {$username} CREATEDB");
    DB::statement("ALTER USER {$username} CREATEROLE");

    // Grant BYPASSRLS privilege if requested
    if ($bypassRls) {
        DB::statement("ALTER USER {$username} BYPASSRLS");
    }

    // Grant privileges to the new central user
    DB::statement("GRANT ALL PRIVILEGES ON DATABASE main to {$username}");
    DB::statement("GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO {$username}");
    DB::statement("GRANT ALL ON SCHEMA public TO {$username}");
    DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL PRIVILEGES ON TABLES TO {$username}");
    DB::statement("GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO {$username}");

    return [$username, $password];
}

class Post extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'post_id');
    }
}

class Comment extends Model
{
    protected $guarded = [];

    protected $table = 'comments';

    public $timestamps = false;

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }
}

class Note extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }
}

class Category extends Model
{
    protected $guarded = [];
}

class Author extends Model
{
    protected $guarded = [];
}

class Order extends Model
{
    protected $guarded = [];
}
