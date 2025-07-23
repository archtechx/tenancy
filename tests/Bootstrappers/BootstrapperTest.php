<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Events\TenantCreated;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\FilesystemAdapter;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Bootstrappers\CacheTagsBootstrapper;
use Stancl\Tenancy\Bootstrappers\RedisTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;
use function Stancl\Tenancy\Tests\pest;

beforeEach(function () {
    $this->mockConsoleOutput = false;

    config([
        'cache.default' => 'redis',
        'tenancy.cache.stores' => ['redis'],
    ]);

    Event::listen(
        TenantCreated::class,
        JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
            return $event->tenant;
        })->toListener()
    );

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
});

test('database data is separated', function () {
    config(['tenancy.bootstrappers' => [DatabaseTenancyBootstrapper::class]]);

    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();

    pest()->artisan('tenants:migrate');

    tenancy()->initialize($tenant1);

    // Create Foo user
    DB::table('users')->insert(['name' => 'Foo', 'email' => 'foo@bar.com', 'password' => 'secret']);
    expect(DB::table('users')->get())->toHaveCount(1);

    tenancy()->initialize($tenant2);

    // Assert Foo user is not in this DB
    expect(DB::table('users')->get())->toHaveCount(0);
    // Create Bar user
    DB::table('users')->insert(['name' => 'Bar', 'email' => 'bar@bar.com', 'password' => 'secret']);
    expect(DB::table('users')->get())->toHaveCount(1);

    tenancy()->initialize($tenant1);

    // Assert Bar user is not in this DB
    expect(DB::table('users')->get())->toHaveCount(1);
    expect(DB::table('users')->first()->name)->toBe('Foo');
});

test('cache data is separated', function (string $store, string $bootstrapper) {
    config([
        'tenancy.bootstrappers' => [$bootstrapper],
        'tenancy.cache.stores' => [$store],
        'cache.default' => $store,
    ]);

    if ($store === 'database') {
        config([
            'cache.stores.database.connection' => 'central',
            'cache.stores.database.lock_connection' => 'central',
        ]);

        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
    }

    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();

    cache()->set('foo', 'central');
    expect(Cache::get('foo'))->toBe('central');

    tenancy()->initialize($tenant1);

    // Assert central cache doesn't leak to tenant context
    expect(Cache::has('foo'))->toBeFalse();

    cache()->set('foo', 'bar');
    expect(Cache::get('foo'))->toBe('bar');

    tenancy()->initialize($tenant2);

    // Assert one tenant's data doesn't leak to another tenant
    expect(Cache::has('foo'))->toBeFalse();

    cache()->set('foo', 'xyz');
    expect(Cache::get('foo'))->toBe('xyz');

    tenancy()->initialize($tenant1);

    // Assert data didn't leak to original tenant
    expect(Cache::get('foo'))->toBe('bar');

    tenancy()->end();

    // Assert central is still the same
    expect(Cache::get('foo'))->toBe('central');
})->with([
    ['redis', CacheTagsBootstrapper::class],
    ['memcached', CacheTagsBootstrapper::class],

    ['file', FilesystemTenancyBootstrapper::class],

    ['redis', CacheTenancyBootstrapper::class],
    ['apc', CacheTenancyBootstrapper::class],
    ['memcached', CacheTenancyBootstrapper::class],
    ['database', CacheTenancyBootstrapper::class],
    ['dynamodb', CacheTenancyBootstrapper::class],
]);

test('redis data is separated', function () {
    config(['tenancy.bootstrappers' => [
        RedisTenancyBootstrapper::class,
    ]]);

    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();

    tenancy()->initialize($tenant1);
    Redis::set('foo', 'bar');
    expect(Redis::get('foo'))->toBe('bar');

    tenancy()->initialize($tenant2);
    expect(Redis::get('foo'))->toBe(null);
    Redis::set('foo', 'xyz');
    Redis::set('abc', 'def');
    expect(Redis::get('foo'))->toBe('xyz');
    expect(Redis::get('abc'))->toBe('def');

    tenancy()->initialize($tenant1);
    expect(Redis::get('foo'))->toBe('bar');
    expect(Redis::get('abc'))->toBe(null);

    $tenant3 = Tenant::create();
    tenancy()->initialize($tenant3);
    expect(Redis::get('foo'))->toBe(null);
    expect(Redis::get('abc'))->toBe(null);
});

test('filesystem data is separated', function () {
    config([
        'tenancy.bootstrappers' => [FilesystemTenancyBootstrapper::class],
        'session.driver' => 'file',
        'cache.default' => 'file',
        'tenancy.cache.stores' => ['file'],
    ]);

    $old_storage_path = storage_path();
    $old_storage_facade_roots = [];
    foreach (config('tenancy.filesystem.disks') as $disk) {
        $old_storage_facade_roots[$disk] = config("filesystems.disks.{$disk}.root");
    }

    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();

    tenancy()->initialize($tenant1);

    Storage::disk('public')->put('foo', 'bar');
    expect(Storage::disk('public')->get('foo'))->toBe('bar');

    tenancy()->initialize($tenant2);
    expect(Storage::disk('public')->exists('foo'))->toBeFalse();
    Storage::disk('public')->put('foo', 'xyz');
    Storage::disk('public')->put('abc', 'def');
    expect(Storage::disk('public')->get('foo'))->toBe('xyz');
    expect(Storage::disk('public')->get('abc'))->toBe('def');

    tenancy()->initialize($tenant1);
    expect(Storage::disk('public')->get('foo'))->toBe('bar');
    expect(Storage::disk('public')->exists('abc'))->toBeFalse();

    $tenant3 = Tenant::create();
    tenancy()->initialize($tenant3);
    expect(Storage::disk('public')->exists('foo'))->toBeFalse();
    expect(Storage::disk('public')->exists('abc'))->toBeFalse();

    $expected_storage_path = $old_storage_path . '/tenant' . tenant('id'); // /tenant = suffix base

    // Check that disk prefixes respect the root_override logic
    expect(getDiskPrefix('local'))->toBe($expected_storage_path . '/app/');
    expect(getDiskPrefix('public'))->toBe($expected_storage_path . '/app/public/');
    pest()->assertSame('tenant' . tenant('id') . '/', getDiskPrefix('s3'), '/');

    // Check suffixing logic
    $new_storage_path = storage_path();
    expect($new_storage_path)->toEqual($expected_storage_path);

    // Check cache path
    $cachePath = cache()->store()->getStore()->getDirectory();
    expect($cachePath)
        ->toBe(config('cache.stores.file.path'))
        ->toBe(storage_path('framework/cache/data'));
    expect($cachePath)->toContain(tenant('id'));

    // Check session path
    $sessionPath = invade(app('session')->driver()->getHandler())->path;
    expect($sessionPath)
        ->toBe(config('session.files'))
        ->toBe(storage_path('framework/sessions'));
    expect($sessionPath)->toContain(tenant('id'));

    // URL generation is tested separately in FilesystemTenancyBootstrapperTest
});

function getDiskPrefix(string $disk): string
{
    /** @var FilesystemAdapter $disk */
    $disk = Storage::disk($disk);
    $adapter = $disk->getAdapter();
    $prefix = invade(invade($adapter)->prefixer)->prefix;

    return $prefix;
}
