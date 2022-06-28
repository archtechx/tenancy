<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Stancl\Tenancy\Database\Concerns\BelongsToPrimaryModel;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;
use Stancl\Tenancy\Database\Concerns\HasScopedValidationRules;

uses(Stancl\Tenancy\Tests\TestCase::class);
use Stancl\Tenancy\Tests\Etc\Tenant as TestTenant;

beforeEach(function () {
    BelongsToTenant::$tenantIdColumn = 'tenant_id';

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

    config(['tenancy.tenant_model' => Tenant::class]);
});

test('primary models are scoped to the current tenant', function () {
    // acme context
    tenancy()->initialize($acme = Tenant::create([
        'id' => 'acme',
    ]));

    $post = Post::create(['text' => 'Foo']);

    $this->assertSame('acme', $post->tenant_id);
    $this->assertSame('acme', $post->tenant->id);

    $post = Post::first();

    $this->assertSame('acme', $post->tenant_id);
    $this->assertSame('acme', $post->tenant->id);

    // ======================================
    // foobar context
    tenancy()->initialize($foobar = Tenant::create([
        'id' => 'foobar',
    ]));

    $post = Post::create(['text' => 'Bar']);

    $this->assertSame('foobar', $post->tenant_id);
    $this->assertSame('foobar', $post->tenant->id);

    $post = Post::first();

    $this->assertSame('foobar', $post->tenant_id);
    $this->assertSame('foobar', $post->tenant->id);

    // ======================================
    // acme context again

    tenancy()->initialize($acme);

    $post = Post::first();
    $this->assertSame('acme', $post->tenant_id);
    $this->assertSame('acme', $post->tenant->id);

    // Assert foobar models are inaccessible in acme context
    $this->assertSame(1, Post::count());
});

test('primary models are not scoped in the central context', function () {
    $this->primary_models_are_scoped_to_the_current_tenant();

    tenancy()->end();

    $this->assertSame(2, Post::count());
});

test('secondary models are scoped to the current tenant when accessed via primary model', function () {
    // acme context
    tenancy()->initialize($acme = Tenant::create([
        'id' => 'acme',
    ]));

    $post = Post::create(['text' => 'Foo']);
    $post->comments()->create(['text' => 'Comment text']);

    // ================
    // foobar context
    tenancy()->initialize($foobar = Tenant::create([
        'id' => 'foobar',
    ]));

    $post = Post::create(['text' => 'Bar']);
    $post->comments()->create(['text' => 'Comment text 2']);

    // ================
    // acme context again
    tenancy()->initialize($acme);
    $this->assertSame(1, Post::count());
    $this->assertSame(1, Post::first()->comments->count());
});

test('secondary models are n o t scoped to the current tenant when accessed directly', function () {
    $this->secondary_models_are_scoped_to_the_current_tenant_when_accessed_via_primary_model();

    // We're in acme context
    $this->assertSame('acme', tenant('id'));

    $this->assertSame(2, Comment::count());
});

test('secondary models a r e scoped to the current tenant when accessed directly a n d p a r e n t r e l a t i o n s h i p t r a i t i s u s e d', function () {
    $acme = Tenant::create([
        'id' => 'acme',
    ]);

    $acme->run(function () {
        $post = Post::create(['text' => 'Foo']);
        $post->scoped_comments()->create(['text' => 'Comment Text']);

        $this->assertSame(1, Post::count());
        $this->assertSame(1, ScopedComment::count());
    });

    $foobar = Tenant::create([
        'id' => 'foobar',
    ]);

    $foobar->run(function () {
        $this->assertSame(0, Post::count());
        $this->assertSame(0, ScopedComment::count());

        $post = Post::create(['text' => 'Bar']);
        $post->scoped_comments()->create(['text' => 'Comment Text 2']);

        $this->assertSame(1, Post::count());
        $this->assertSame(1, ScopedComment::count());
    });

    // Global context
    $this->assertSame(2, ScopedComment::count());
});

test('secondary models are n o t scoped in the central context', function () {
    $this->secondary_models_are_scoped_to_the_current_tenant_when_accessed_via_primary_model();

    tenancy()->end();

    $this->assertSame(2, Comment::count());
});

test('global models are not scoped at all', function () {
    Schema::create('global_resources', function (Blueprint $table) {
        $table->increments('id');
        $table->string('text');
    });

    GlobalResource::create(['text' => 'First']);
    GlobalResource::create(['text' => 'Second']);

    $acme = Tenant::create([
        'id' => 'acme',
    ]);

    $acme->run(function () {
        $this->assertSame(2, GlobalResource::count());

        GlobalResource::create(['text' => 'Third']);
        GlobalResource::create(['text' => 'Fourth']);
    });

    $this->assertSame(4, GlobalResource::count());
});

test('tenant id and relationship is auto added when creating primary resources in tenant context', function () {
    tenancy()->initialize($acme = Tenant::create([
        'id' => 'acme',
    ]));

    $post = Post::create(['text' => 'Foo']);

    $this->assertSame('acme', $post->tenant_id);
    $this->assertTrue($post->relationLoaded('tenant'));
    $this->assertSame($acme, $post->tenant);
    $this->assertSame(tenant(), $post->tenant);
});

test('tenant id is not auto added when creating primary resources in central context', function () {
    $this->expectException(QueryException::class);

    Post::create(['text' => 'Foo']);
});

test('tenant id column name can be customized', function () {
    BelongsToTenant::$tenantIdColumn = 'team_id';

    Schema::drop('comments');
    Schema::drop('posts');
    Schema::create('posts', function (Blueprint $table) {
        $table->increments('id');
        $table->string('text');

        $table->string('team_id');

        $table->foreign('team_id')->references('id')->on('tenants')->onUpdate('cascade')->onDelete('cascade');
    });

    tenancy()->initialize($acme = Tenant::create([
        'id' => 'acme',
    ]));

    $post = Post::create(['text' => 'Foo']);

    $this->assertSame('acme', $post->team_id);

    // ======================================
    // foobar context
    tenancy()->initialize($foobar = Tenant::create([
        'id' => 'foobar',
    ]));

    $post = Post::create(['text' => 'Bar']);

    $this->assertSame('foobar', $post->team_id);

    $post = Post::first();

    $this->assertSame('foobar', $post->team_id);

    // ======================================
    // acme context again

    tenancy()->initialize($acme);

    $post = Post::first();
    $this->assertSame('acme', $post->team_id);

    // Assert foobar models are inaccessible in acme context
    $this->assertSame(1, Post::count());
});

test('the model returned by the tenant helper has unique and exists validation rules', function () {
    Schema::table('posts', function (Blueprint $table) {
        $table->string('slug')->nullable();
        $table->unique(['tenant_id', 'slug']);
    });

    tenancy()->initialize($acme = Tenant::create([
        'id' => 'acme',
    ]));

    Post::create(['text' => 'Foo', 'slug' => 'foo']);
    $data = ['text' => 'Foo 2', 'slug' => 'foo'];

    $uniqueFails = Validator::make($data, [
        'slug' => 'unique:posts',
    ])->fails();
    $existsFails = Validator::make($data, [
        'slug' => 'exists:posts',
    ])->fails();

    // Assert that 'unique' and 'exists' aren't scoped by default
    // $this->assertFalse($uniqueFails); // todo get these two assertions to pass. for some reason, the validator is passing for both 'unique' and 'exists'
    // $this->assertTrue($existsFails); // todo get these two assertions to pass. for some reason, the validator is passing for both 'unique' and 'exists'

    $uniqueFails = Validator::make($data, [
        'slug' => tenant()->unique('posts'),
    ])->fails();
    $existsFails = Validator::make($data, [
        'slug' => tenant()->exists('posts'),
    ])->fails();

    // Assert that tenant()->unique() and tenant()->exists() are scoped
    $this->assertTrue($uniqueFails);
    $this->assertFalse($existsFails);
});

// Helpers
function comments()
{
    return test()->hasMany(Comment::class);
}

function scoped_comments()
{
    return test()->hasMany(Comment::class);
}

function post()
{
    return test()->belongsTo(Post::class);
}

function getRelationshipToPrimaryModel(): string
{
    return 'post';
}
