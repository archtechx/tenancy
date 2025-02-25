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
use Stancl\Tenancy\Commands\CreateUserWithRLSPolicies;
use Stancl\Tenancy\RLS\PolicyManagers\TableRLSManager;
use Stancl\Tenancy\Bootstrappers\PostgresRLSBootstrapper;
use Stancl\Tenancy\Database\Exceptions\RecursiveRelationshipException;
use function Stancl\Tenancy\Tests\pest;

beforeEach(function () {
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

test('queries are correctly scoped using RLS', function() {
    // 3-levels deep relationship
    Schema::create('notes', function (Blueprint $table) {
        $table->id();
        $table->string('text')->default('foo');
        // no rls comment needed, $scopeByDefault is set to true
        $table->foreignId('comment_id')->onUpdate('cascade')->onDelete('cascade')->constrained('comments');
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
        'tenant_id' => $tenant1->getTenantKey(),
        'author_id' => Author::create(['name' => 'author1', 'tenant_id' => $tenant1->getTenantKey()])->id,
        'category_id' => Category::create(['name' => 'category1', 'tenant_id' => $tenant1->getTenantKey()])->id,
    ]);

    $post1Comment = Comment::create(['text' => 'first comment', 'post_id' => $post1->id]);

    $post1Comment->notes()->create(['text' => 'foo']);

    tenancy()->initialize($tenant2);

    $post2 = Post::create([
        'text' => 'second post',
        'tenant_id' => $tenant2->getTenantKey(),
        'author_id' => Author::create(['name' => 'author2', 'tenant_id' => $tenant2->getTenantKey()])->id,
        'category_id' => Category::create(['name' => 'category2', 'tenant_id' => $tenant2->getTenantKey()])->id
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
    expect(fn () => DB::statement("INSERT INTO posts (text, author_id, category_id, tenant_id) VALUES ('third post', 1, 1, '{$tenant1->getTenantKey()}')"))
        ->toThrow(QueryException::class);

    expect(fn () => DB::statement("INSERT INTO comments (text, post_id) VALUES ('third comment', {$post1->id})"))
        ->toThrow(QueryException::class);

    expect(fn () => DB::statement("INSERT INTO notes (text, comment_id) VALUES ('baz', {$post1Comment->id})"))
        ->toThrow(QueryException::class);
});

test('table rls manager generates relationship trees with tables related to the tenants table', function (bool $scopeByDefault) {
    TableRLSManager::$scopeByDefault = $scopeByDefault;

    /** @var TableRLSManager $manager */
    $manager = app(TableRLSManager::class);

    $expectedTrees = [
        'authors' => [
            // Directly related to tenants
            'tenant_id' => [
                [
                    [
                        'foreignKey' => 'tenant_id',
                        'foreignTable' => 'tenants',
                        'foreignId' => 'id',
                        'nullable' => false,
                    ]
                ],
            ],
        ],
        'comments' => [
            // Tree starting from the post_id foreign key
            'post_id' => [
                [
                    [
                        'foreignKey' => 'post_id',
                        'foreignTable' => 'posts',
                        'foreignId' => 'id',
                        'nullable' => false,
                    ],
                    [
                        'foreignKey' => 'author_id',
                        'foreignTable' => 'authors',
                        'foreignId' => 'id',
                        'nullable' => false,
                    ],
                    [
                        'foreignKey' => 'tenant_id',
                        'foreignTable' => 'tenants',
                        'foreignId' => 'id',
                        'nullable' => false,
                    ],
                ],
                [
                    [
                        'foreignKey' => 'post_id',
                        'foreignTable' => 'posts',
                        'foreignId' => 'id',
                        'nullable' => false,
                    ],
                    [
                        'foreignKey' => 'tenant_id',
                        'foreignTable' => 'tenants',
                        'foreignId' => 'id',
                        'nullable' => true,
                    ],
                ],
            ],
        ],
        'posts' => [
            // Category tree gets excluded because the category table is related to the tenant table
            // only through a column with the 'no-rls' comment
            'author_id' => [
                [
                    [
                        'foreignKey' => 'author_id',
                        'foreignTable' => 'authors',
                        'foreignId' => 'id',
                        'nullable' => false,
                    ],
                    [
                        'foreignKey' => 'tenant_id',
                        'foreignTable' => 'tenants',
                        'foreignId' => 'id',
                        'nullable' => false,
                    ]
                ],
            ],
            'tenant_id' => [
                [
                    [
                        'foreignKey' => 'tenant_id',
                        'foreignTable' => 'tenants',
                        'foreignId' => 'id',
                        'nullable' => true,
                    ]
                ]
            ],
        ],
        // Articles table is ignored because it's not related to the tenant table in any way
        // Reactions table is ignored because of the 'no-rls' comment on the comment_id column
        // Categories table is ignored because of the 'no-rls' comment on the tenant_id column
    ];

    expect($manager->generateTrees())->toEqual($expectedTrees);

    $expectedShortestPaths = [
        'authors' => [
            [
                'foreignKey' => 'tenant_id',
                'foreignTable' => 'tenants',
                'foreignId' => 'id',
            ],
        ],
        'posts' => [
            [
                'foreignKey' => 'author_id',
                'foreignTable' => 'authors',
                'foreignId' => 'id',
            ],
            [
                'foreignKey' => 'tenant_id',
                'foreignTable' => 'tenants',
                'foreignId' => 'id',
            ],
        ],
        'comments' => [
            [
                'foreignKey' => 'post_id',
                'foreignTable' => 'posts',
                'foreignId' => 'id',
            ],
            [
                'foreignKey' => 'author_id',
                'foreignTable' => 'authors',
                'foreignId' => 'id',
            ],
            [
                'foreignKey' => 'tenant_id',
                'foreignTable' => 'tenants',
                'foreignId' => 'id',
            ],
        ],
    ];

    expect($manager->shortestPaths())->toEqual($expectedShortestPaths);

    // Only related to the tenants table through nullable columns – tenant_id and indirectly through post_id
    Schema::create('ratings', function (Blueprint $table) {
        $table->id();
        $table->integer('stars')->default(0);

        $table->unsignedBigInteger('post_id')->nullable()->comment('rls');
        $table->foreign('post_id')->references('id')->on('posts')->onUpdate('cascade')->onDelete('cascade');

        // No 'rls' comment – should get excluded from full trees when using explicit scoping
        $table->string('tenant_id')->nullable();
        $table->foreign('tenant_id')->references('id')->on('tenants')->onUpdate('cascade')->onDelete('cascade');

        $table->timestamps();
    });

    // The shortest paths should include a path for the ratings table
    // That leads through tenant_id – when scoping by default is enabled, that's the shortest path
    // When scoping by default is disabled, the shortest path leads through post_id
    // This behavior is handled by the manager's generateTrees() method, which is called by shortestPaths()
    $shortestPaths = $manager->shortestPaths();

    $expectedShortestPath = $scopeByDefault ? [
        [
            'foreignKey' => 'tenant_id',
            'foreignTable' => 'tenants',
            'foreignId' => 'id',
        ],
    ] : [
        [
            'foreignKey' => 'post_id',
            'foreignTable' => 'posts',
            'foreignId' => 'id',
        ],
        [
            'foreignKey' => 'tenant_id',
            'foreignTable' => 'tenants',
            'foreignId' => 'id',
        ],
    ];

    expect($shortestPaths['ratings'])->toBe($expectedShortestPath);

    // Add non-nullable comment_id foreign key
    Schema::table('ratings', function (Blueprint $table) {
        $table->foreignId('comment_id')->onUpdate('cascade')->onDelete('cascade')->comment('rls')->constrained('comments');
    });

    // Non-nullable paths are preferred over nullable paths
    // The shortest paths should include a path for the ratings table
    // That leads through comment_id instead of tenant_id
    $shortestPaths = $manager->shortestPaths();

    expect($shortestPaths['ratings'])->toBe([
        [
            'foreignKey' => 'comment_id',
            'foreignTable' => 'comments',
            'foreignId' => 'id',
        ],
        [
            'foreignKey' => 'post_id',
            'foreignTable' => 'posts',
            'foreignId' => 'id',
        ],
        [
            'foreignKey' => 'author_id',
            'foreignTable' => 'authors',
            'foreignId' => 'id',
        ],
        [
            'foreignKey' => 'tenant_id',
            'foreignTable' => 'tenants',
            'foreignId' => 'id',
        ],
    ]);
})->with([true, false]);

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
                'foreignKey' => 'tenant_id',
                'foreignTable' => 'tenants',
                'foreignId' => 'id',
            ],
        ],
        'secondaries' => [
            [
                'foreignKey' => 'primary_id',
                'foreignTable' => 'primaries',
                'foreignId' => 'id',
            ],
            [
                'foreignKey' => 'tenant_id',
                'foreignTable' => 'tenants',
                'foreignId' => 'id',
            ],
        ],
        'foo' => [
            [
                'foreignKey' => 'secondary_id',
                'foreignTable' => 'secondaries',
                'foreignId' => 'id',
            ],
            [
                'foreignKey' => 'primary_id',
                'foreignTable' => 'primaries',
                'foreignId' => 'id',
            ],
            [
                'foreignKey' => 'tenant_id',
                'foreignTable' => 'tenants',
                'foreignId' => 'id',
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

test('table manager throws an exception when encountering a recursive relationship', function() {
    Schema::create('recursive_posts', function (Blueprint $table) {
        $table->id();
        $table->foreignId('highlighted_comment_id')->constrained('comments')->nullable()->comment('rls');
    });

    Schema::table('comments', function (Blueprint $table) {
        $table->foreignId('recursive_post_id')->constrained('recursive_posts')->comment('rls');
    });

    expect(fn () => app(TableRLSManager::class)->generateTrees())->toThrow(RecursiveRelationshipException::class);
});

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
