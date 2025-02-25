<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Validator;
use Stancl\Tenancy\Database\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;
use Stancl\Tenancy\Database\Concerns\BelongsToPrimaryModel;
use Stancl\Tenancy\Database\Concerns\HasScopedValidationRules;
use function Stancl\Tenancy\Tests\pest;

beforeEach(function () {
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

    config(['tenancy.models.tenant' => SingleDatabaseTenant::class]);
});

test('primary models are scoped to the current tenant', function () {
    // acme context
    tenancy()->initialize($acme = SingleDatabaseTenant::create([
        'id' => 'acme',
    ]));

    $post = SingleDatabasePost::create(['text' => 'Foo']);

    expect($post->tenant_id)->toBe('acme');
    expect($post->tenant->id)->toBe('acme');

    $post = SingleDatabasePost::first();

    expect($post->tenant_id)->toBe('acme');
    expect($post->tenant->id)->toBe('acme');

    // ======================================
    // foobar context
    tenancy()->initialize(SingleDatabaseTenant::create([
        'id' => 'foobar',
    ]));

    $post = SingleDatabasePost::create(['text' => 'Bar']);

    expect($post->tenant_id)->toBe('foobar');
    expect($post->tenant->id)->toBe('foobar');

    $post = SingleDatabasePost::first();

    expect($post->tenant_id)->toBe('foobar');
    expect($post->tenant->id)->toBe('foobar');

    // ======================================
    // acme context again

    tenancy()->initialize($acme);

    $post = SingleDatabasePost::first();
    expect($post->tenant_id)->toBe('acme');
    expect($post->tenant->id)->toBe('acme');

    // Assert foobar models are inaccessible in acme context
    expect(SingleDatabasePost::count())->toBe(1);

    // Primary models are not scoped in the central context
    tenancy()->end();

    expect(SingleDatabasePost::count())->toBe(2);
});

test('secondary models ARE scoped to the current tenant when accessed directly and parent relationship trait is used', function () {
    $acme = SingleDatabaseTenant::create([
        'id' => 'acme',
    ]);

    $acme->run(function () {
        $post = SingleDatabasePost::create(['text' => 'Foo']);
        $post->comments()->create(['text' => 'Comment Text']);

        expect(SingleDatabasePost::count())->toBe(1);
        expect(ScopedComment::count())->toBe(1);
    });

    $foobar = SingleDatabaseTenant::create([
        'id' => 'foobar',
    ]);

    $foobar->run(function () {
        expect(SingleDatabasePost::count())->toBe(0);
        expect(ScopedComment::count())->toBe(0);

        $post = SingleDatabasePost::create(['text' => 'Bar']);
        $post->comments()->create(['text' => 'Comment Text 2']);

        expect(SingleDatabasePost::count())->toBe(1);
        expect(ScopedComment::count())->toBe(1);
        // whereas...
        expect(Comment::count())->toBe(2);
    });

    // Global context
    expect(ScopedComment::count())->toBe(2);
});

test('secondary models are scoped correctly', function () {
    // Secondary models are scoped to the current tenant when accessed via primary model
    // acme context
    tenancy()->initialize($acme = SingleDatabaseTenant::create([
        'id' => 'acme',
    ]));

    $post = SingleDatabasePost::create(['text' => 'Foo']);
    $post->comments()->create(['text' => 'Comment text']);

    // ================
    // foobar context
    tenancy()->initialize(SingleDatabaseTenant::create([
        'id' => 'foobar',
    ]));

    $post = SingleDatabasePost::create(['text' => 'Bar']);
    $post->comments()->create(['text' => 'Comment text 2']);

    // ================
    // acme context again
    tenancy()->initialize($acme);
    expect(SingleDatabasePost::count())->toBe(1);
    expect(SingleDatabasePost::first()->comments->count())->toBe(1);

    // Secondary models are not scoped to the current tenant when accessed directly
    expect(tenant('id'))->toBe('acme');

    expect(BaseComment::count())->toBe(2);

    // secondary models are not scoped in the central context
    tenancy()->end();

    expect(BaseComment::count())->toBe(2);
});

test('global models are not scoped at all', function () {
    Schema::create('global_resources', function (Blueprint $table) {
        $table->increments('id');
        $table->string('text');
    });

    GlobalResource::create(['text' => 'First']);
    GlobalResource::create(['text' => 'Second']);

    $acme = SingleDatabaseTenant::create([
        'id' => 'acme',
    ]);

    $acme->run(function () {
        expect(GlobalResource::count())->toBe(2);

        GlobalResource::create(['text' => 'Third']);
        GlobalResource::create(['text' => 'Fourth']);
    });

    expect(GlobalResource::count())->toBe(4);
});

test('tenant id and relationship is auto added when creating primary resources in tenant context', function () {
    tenancy()->initialize($acme = SingleDatabaseTenant::create([
        'id' => 'acme',
    ]));

    $post = SingleDatabasePost::create(['text' => 'Foo']);

    expect($post->tenant_id)->toBe('acme');
    expect($post->relationLoaded('tenant'))->toBeTrue();
    expect($post->tenant)->toBe($acme);
    expect($post->tenant)->toBe(tenant());
});

test('tenant id is not auto added when creating primary resources in central context', function () {
    pest()->expectException(QueryException::class);

    SingleDatabasePost::create(['text' => 'Foo']);
});

test('tenant id column name can be customized', function () {
    config(['tenancy.models.tenant_key_column' => 'team_id']);

    Schema::drop('comments');
    Schema::drop('posts');
    Schema::create('posts', function (Blueprint $table) {
        $table->increments('id');
        $table->string('text');

        $table->string('team_id');

        $table->foreign('team_id')->references('id')->on('tenants')->onUpdate('cascade')->onDelete('cascade');
    });

    $acme = SingleDatabaseTenant::create([
        'id' => 'acme',
    ]);

    tenancy()->initialize($acme);

    $post = SingleDatabasePost::create(['text' => 'Foo']);

    expect($post->team_id)->toBe('acme');

    // ======================================
    // foobar context
    tenancy()->initialize($foobar = SingleDatabaseTenant::create([
        'id' => 'foobar',
    ]));

    $post = SingleDatabasePost::create(['text' => 'Bar']);

    expect($post->team_id)->toBe('foobar');

    $post = SingleDatabasePost::first();

    expect($post->team_id)->toBe('foobar');

    // ======================================
    // acme context again

    tenancy()->initialize($acme);

    $post = SingleDatabasePost::first();
    expect($post->team_id)->toBe('acme');

    // Assert foobar models are inaccessible in acme context
    expect(SingleDatabasePost::count())->toBe(1);
});

test('the model returned by the tenant helper has unique and exists validation rules', function () {
    Schema::table('posts', function (Blueprint $table) {
        $table->string('slug')->nullable();
        $table->unique(['tenant_id', 'slug']);
    });

    tenancy()->initialize($acme = SingleDatabaseTenant::create([
        'id' => 'acme',
    ]));

    SingleDatabasePost::create(['text' => 'Foo', 'slug' => 'foo']);
    $data = ['text' => 'Foo 2', 'slug' => 'foo'];

    $uniqueFails = Validator::make($data, [
        'slug' => 'unique:posts',
    ])->fails();
    $existsPass = Validator::make($data, [
        'slug' => 'exists:posts',
    ])->passes();

    // Assert that 'unique' and 'exists' aren't scoped by default
    expect($uniqueFails)->toBeTrue(); // Expect unique rule failed to pass because slug 'foo' already exists
    expect($existsPass)->toBeTrue(); // Expect exists rule pass because slug 'foo' exists

    $uniqueFails = Validator::make($data, [
        'slug' => tenant()->unique('posts'),
    ])->fails();
    $existsFails = Validator::make($data, [
        'slug' => tenant()->exists('posts'),
    ])->fails();

    // Assert that tenant()->unique() and tenant()->exists() are scoped
    expect($uniqueFails)->toBeTrue();
    expect($existsFails)->toBeFalse();
});

class SingleDatabaseTenant extends Tenant
{
    use HasScopedValidationRules;
}

class SingleDatabasePost extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    public $table = 'posts';

    public $timestamps = false;

    public function comments()
    {
        return $this->hasMany(BaseComment::class, 'post_id');
    }
}

class BaseComment extends Model
{
    protected $guarded = [];

    protected $table = 'comments';

    public $timestamps = false;

    public function post()
    {
        return $this->belongsTo(SingleDatabasePost::class);
    }
}

// accessed via the comments() relationship (same table as BaseComment)
// however, when used directly, the model scopes queries to the current tenant
// unlike BaseComment
class ScopedComment extends BaseComment
{
    use BelongsToPrimaryModel;

    protected $table = 'comments';

    public function getRelationshipToPrimaryModel(): string
    {
        return 'post';
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(SingleDatabasePost::class);
    }
}

class GlobalResource extends Model
{
    protected $guarded = [];

    public $timestamps = false;
}
