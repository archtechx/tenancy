<?php

use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseSessionBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\RedisTenancyBootstrapper;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;
use Stancl\Tenancy\Middleware\PreventAccessFromUnwantedDomains;
use Stancl\Tenancy\Tests\Etc\Tenant;
use function Stancl\Tenancy\Tests\pest;

// todo@tests write similar low-level tests for the cache bootstrapper? including the database driver in a single-db setup

beforeEach(function () {
    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);

    // Middleware priority logic
    $tenancyMiddleware = array_merge([PreventAccessFromUnwantedDomains::class], config('tenancy.identification.middleware'));
    foreach (array_reverse($tenancyMiddleware) as $middleware) {
        app()->make(\Illuminate\Contracts\Http\Kernel::class)->prependToMiddlewarePriority($middleware);
    }
});

test('file sessions are separated', function (bool $scopeSessions) {
    config([
        'tenancy.bootstrappers' => [FilesystemTenancyBootstrapper::class],
        'tenancy.filesystem.suffix_storage_path' => false,
        'tenancy.filesystem.scope_sessions' => $scopeSessions,
        'session.driver' => 'file',
    ]);

    $sessionPath = fn () => invade(app('session')->driver()->getHandler())->path;

    expect($sessionPath())->toBe(storage_path('framework/sessions'));
    File::cleanDirectory(storage_path("framework/sessions")); // clean up the sessions dir from past test runs

    $tenant = Tenant::create();
    $tenant->enter();

    if ($scopeSessions) {
        expect($sessionPath())->toBe(storage_path('tenant' . $tenant->getTenantKey() . '/framework/sessions'));
    } else {
        expect($sessionPath())->toBe(storage_path('framework/sessions'));
    }

    $tenant->leave();

    Route::middleware(StartSession::class, InitializeTenancyByPath::class)->get('/{tenant}/foo', fn () => 'bar');

    if ($scopeSessions) {
        expect(File::files(storage_path("tenant{$tenant->id}/framework/sessions")))->toHaveCount(0);
    } else {
        expect(File::exists(storage_path("tenant{$tenant->id}/framework/sessions")))->toBeFalse();
    }

    pest()->get("/{$tenant->id}/foo");

    if ($scopeSessions) {
        expect(File::files(storage_path("tenant{$tenant->id}/framework/sessions")))->toHaveCount(1);
        expect(File::files(storage_path("framework/sessions")))->toHaveCount(0);
    } else {
        expect(File::exists(storage_path("tenant{$tenant->id}/framework/sessions")))->toBeFalse();
        expect(File::files(storage_path("framework/sessions")))->toHaveCount(1);
    }
})->with([true, false]);

test('redis sessions are separated using the redis bootstrapper', function (bool $bootstrappedEnabled) {
    config([
        'tenancy.bootstrappers' => $bootstrappedEnabled ? [RedisTenancyBootstrapper::class] : [],
        'session.driver' => 'redis',
    ]);

    $redisClient = app('session')->driver()->getHandler()->getCache()->getStore()->connection()->client();
    expect($redisClient->getOption($redisClient::OPT_PREFIX))->toBe('foo'); // default prefix configured in TestCase

    expect(Redis::keys('*'))->toHaveCount(0);

    $tenant = Tenant::create();
    Route::middleware(StartSession::class, InitializeTenancyByPath::class)->get('/{tenant}/foo', fn () => 'bar');
    pest()->get("/{$tenant->id}/foo");

    expect($redisClient->getOption($redisClient::OPT_PREFIX) === "tenant_{$tenant->id}_")->toBe($bootstrappedEnabled);

    expect(array_filter(Redis::keys('*'), function (string $key) use ($tenant) {
        return str($key)->startsWith("tenant_{$tenant->id}_laravel_cache_");
    }))->toHaveCount($bootstrappedEnabled ? 1 : 0);
})->with([true, false]);

test('redis sessions are separated using the cache bootstrapper', function (bool $scopeSessions) {
    config([
        'tenancy.bootstrappers' => [CacheTenancyBootstrapper::class],
        'session.driver' => 'redis',
        'tenancy.cache.stores' => [], // will be implicitly filled
        'tenancy.cache.scope_sessions' => $scopeSessions,
    ]);

    expect(Redis::keys('*'))->toHaveCount(0);

    $tenant = Tenant::create();
    Route::middleware(StartSession::class, InitializeTenancyByPath::class)->get('/{tenant}/foo', fn () => 'bar');
    pest()->get("/{$tenant->id}/foo");

    expect(app('session')->driver()->getHandler()->getCache()->getStore()->getPrefix() === "laravel_cache_tenant_{$tenant->id}_")->toBe($scopeSessions);

    tenancy()->end();
    expect(app('session')->driver()->getHandler()->getCache()->getStore()->getPrefix())->toBe('laravel_cache_');

    expect(array_filter(Redis::keys('*'), function (string $key) use ($tenant) {
        return str($key)->startsWith("foolaravel_cache_tenant_{$tenant->id}");
    }))->toHaveCount($scopeSessions ? 1 : 0);
})->with([true, false]);

test('memcached sessions are separated using the cache bootstrapper', function (bool $scopeSessions) {
    config([
        'tenancy.bootstrappers' => [CacheTenancyBootstrapper::class],
        'session.driver' => 'memcached',
        'tenancy.cache.stores' => [], // will be implicitly filled
        'tenancy.cache.scope_sessions' => $scopeSessions,
    ]);

    $allMemcachedKeys = fn () => cache()->store('memcached')->getStore()->getMemcached()->getAllKeys();

    if (count($allMemcachedKeys()) !== 0) {
        sleep(1);
    }

    expect($allMemcachedKeys())->toHaveCount(0);

    $tenant = Tenant::create();
    Route::middleware(StartSession::class, InitializeTenancyByPath::class)->get('/{tenant}/foo', fn () => 'bar');
    pest()->get("/{$tenant->id}/foo");

    expect(app('session')->driver()->getHandler()->getCache()->getStore()->getPrefix() === "laravel_cache_tenant_{$tenant->id}_")->toBe($scopeSessions);

    tenancy()->end();
    expect(app('session')->driver()->getHandler()->getCache()->getStore()->getPrefix())->toBe('laravel_cache_');

    sleep(1.1); // 1s+ sleep is necessary for getAllKeys() to work. if this causes race conditions or we want to avoid the delay, we can refactor this to some type of a mock
    expect(array_filter($allMemcachedKeys(), function (string $key) use ($tenant) {
        return str($key)->startsWith("laravel_cache_tenant_{$tenant->id}");
    }))->toHaveCount($scopeSessions ? 1 : 0);

    Artisan::call('cache:clear memcached');
})->with([true, false]);

test('dynamodb sessions are separated using the cache bootstrapper', function (bool $scopeSessions) {
    config([
        'tenancy.bootstrappers' => [CacheTenancyBootstrapper::class],
        'session.driver' => 'dynamodb',
        'tenancy.cache.stores' => [], // will be implicitly filled
        'tenancy.cache.scope_sessions' => $scopeSessions,
    ]);

    $allDynamodbKeys = fn () => array_map(fn ($res) => $res['key']['S'], cache()->store('dynamodb')->getStore()->getClient()->scan(['TableName' => 'cache'])['Items']);

    expect($allDynamodbKeys())->toHaveCount(0);

    $tenant = Tenant::create();
    Route::middleware(StartSession::class, InitializeTenancyByPath::class)->get('/{tenant}/foo', fn () => 'bar');
    pest()->get("/{$tenant->id}/foo");

    expect(app('session')->driver()->getHandler()->getCache()->getStore()->getPrefix() === "laravel_cache_tenant_{$tenant->id}_")->toBe($scopeSessions);

    tenancy()->end();
    expect(app('session')->driver()->getHandler()->getCache()->getStore()->getPrefix())->toBe('laravel_cache_');

    expect(array_filter($allDynamodbKeys(), function (string $key) use ($tenant) {
        return str($key)->startsWith("laravel_cache_tenant_{$tenant->id}");
    }))->toHaveCount($scopeSessions ? 1 : 0);
})->with([true, false]);

test('apc sessions are separated using the cache bootstrapper', function (bool $scopeSessions) {
    config([
        'tenancy.bootstrappers' => [CacheTenancyBootstrapper::class],
        'session.driver' => 'apc',
        'tenancy.cache.stores' => [], // will be implicitly filled
        'tenancy.cache.scope_sessions' => $scopeSessions,
    ]);

    $allApcuKeys = fn () => array_column(apcu_cache_info()['cache_list'], 'info');
    expect($allApcuKeys())->toHaveCount(0);

    $tenant = Tenant::create();
    Route::middleware(StartSession::class, InitializeTenancyByPath::class)->get('/{tenant}/foo', fn () => 'bar');
    pest()->get("/{$tenant->id}/foo");

    expect(app('session')->driver()->getHandler()->getCache()->getStore()->getPrefix() === "laravel_cache_tenant_{$tenant->id}_")->toBe($scopeSessions);

    tenancy()->end();
    expect(app('session')->driver()->getHandler()->getCache()->getStore()->getPrefix())->toBe('laravel_cache_');

    expect(array_filter($allApcuKeys(), function (string $key) use ($tenant) {
        return str($key)->startsWith("laravel_cache_tenant_{$tenant->id}");
    }))->toHaveCount($scopeSessions ? 1 : 0);
})->with([true, false]);

test('database sessions are separated regardless of whether the session bootstrapper is enabled', function (bool $sessionBootstrappedEnabled, bool $connectionSet) {
    config([
        'tenancy.bootstrappers' => $sessionBootstrappedEnabled
            ? [DatabaseTenancyBootstrapper::class, DatabaseSessionBootstrapper::class]
            : [DatabaseTenancyBootstrapper::class],
        'session.driver' => 'database',
        'session.connection' => $connectionSet ? 'central' : null,
        'tenancy.migration_parameters.--schema-path' => 'tests/Etc/session_migrations',
    ]);

    Event::listen(
        TenantCreated::class,
        JobPipeline::make([CreateDatabase::class, MigrateDatabase::class])->send(function (TenantCreated $event) {
            return $event->tenant;
        })->toListener()
    );

    pest()->artisan('migrate', [
        '--path' => __DIR__ . '/Etc/session_migrations',
        '--realpath' => true,
    ])->assertExitCode(0);

    expect(DB::connection('central')->table('sessions')->count())->toBe(0);

    $tenant = Tenant::create();
    Route::middleware(StartSession::class, InitializeTenancyByPath::class)->get('/{tenant}/foo', fn () => 'bar');
    pest()->get("/{$tenant->id}/foo");

    expect(invade(app('session')->driver()->getHandler())->connection->getName())->toBe('tenant');

    expect(DB::connection('tenant')->table('sessions')->count())->toBe(1);
    expect(DB::connection('central')->table('sessions')->count())->toBe(0);
})->with([
    [true, true],
    [true, false],
    // [false, true], // when the connection IS set, the session bootstrapper becomes necessary
    [false, false],
]);
