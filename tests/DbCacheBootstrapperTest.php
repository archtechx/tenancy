<?php

declare(strict_types=1);

use Stancl\Tenancy\Bootstrappers\DatabaseCacheBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Event::listen(
        TenantCreated::class,
        JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
            return $event->tenant;
        })->toListener()
    );

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
});

test('DatabaseCacheBootstrapper makes cache use the tenant connection', function () {
    config([
        'cache.default' => 'database',
        'tenancy.bootstrappers' => [
            DatabaseTenancyBootstrapper::class,
            DatabaseCacheBootstrapper::class, // Used instead of CacheTenancyBootstrapper
        ],
    ]);

    // DB query for verifying that the cache is stored in DB
    // under the 'foo' key (prefixed by the default cache prefix).
    $databaseCacheKey = config('cache.prefix') . 'foo';
    $retrieveFooCacheUsingDbQuery = fn () => DB::selectOne("SELECT * FROM `cache` WHERE `key` = '{$databaseCacheKey}'")?->value;

    $createCacheTables = function () {
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
    };

    // Create cache tables in central DB
    $createCacheTables();

    $tenant = Tenant::create();
    $tenant2 = Tenant::create();

    // Create cache tables in tenant DBs
    // With this bootstrapper, cache will be saved in these tenant DBs instead of the central DB
    tenancy()->runForMultiple([$tenant, $tenant2], $createCacheTables);

    // Write to cache in central context
    cache()->set('foo', 'central');
    expect(Cache::get('foo'))->toBe('central');
    expect(str($retrieveFooCacheUsingDbQuery())->contains('central'))->toBeTrue();

    tenancy()->initialize($tenant);

    // Central cache doesn't leak to tenant context
    expect(Cache::has('foo'))->toBeFalse();
    // The 'foo' cache key in the tenant's database shouldn't exist
    expect(DB::select('select * from cache'))->toHaveCount(0);

    cache()->set('foo', 'bar');
    expect(Cache::get('foo'))->toBe('bar');
    expect(str($retrieveFooCacheUsingDbQuery())->contains('bar'))->toBeTrue();

    tenancy()->initialize($tenant2);

    // Assert one tenant's data doesn't leak to another tenant
    expect(Cache::has('foo'))->toBeFalse();
    // The 'foo' cache key in another tenant's database shouldn't exist
    expect(DB::select('select * from cache'))->toHaveCount(0);

    cache()->set('foo', 'xyz');
    expect(Cache::get('foo'))->toBe('xyz');
    expect(str($retrieveFooCacheUsingDbQuery())->contains('xyz'))->toBeTrue();

    tenancy()->initialize($tenant);

    // Assert data didn't leak to original tenant
    expect(Cache::get('foo'))->toBe('bar');
    expect(str($retrieveFooCacheUsingDbQuery())->contains('bar'))->toBeTrue();

    tenancy()->end();

    // Assert central is still the same
    // The 'foo' cache value in the central database should be 'central'
    expect(Cache::get('foo'))->toBe('central');
    expect(str($retrieveFooCacheUsingDbQuery())->contains('central'))->toBeTrue();
});
