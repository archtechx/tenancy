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

beforeEach(function () {
    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);

    config([
        'cache.default' => 'database',
        'tenancy.bootstrappers' => [
            DatabaseTenancyBootstrapper::class,
            DatabaseCacheBootstrapper::class, // Used instead of CacheTenancyBootstrapper
        ],
    ]);
});

test('DatabaseCacheBootstrapper uses tenant connection for saving cache', function() {
    pest()->artisan('migrate', [
        '--path' => __DIR__ . '/Etc/cache_migrations',
        '--realpath' => true,
    ])->assertExitCode(0);

    $databaseCacheKey = config('cache.prefix') . 'foo';
    $databaseCacheValue = fn () => DB::selectOne("SELECT * FROM `cache` WHERE `key` = '{$databaseCacheKey}'")?->value;

    $tenant = Tenant::create();
    $tenant2 = Tenant::create();

    pest()->artisan('tenants:migrate', [
        '--path' => __DIR__ . '/Etc/cache_migrations',
        '--realpath' => true,
    ])->assertExitCode(0);

    // Write to cache in central context
    cache(['foo' => 'CENTRAL']);

    tenancy()->initialize($tenant);

    // Write to cache in tenant context
    cache(['foo' => 'TENANT']);

    tenancy()->end();

    // The 'foo' cache value in the central database should be 'CENTRAL'
    expect(cache('foo'))->toBe('CENTRAL');
    expect(str($databaseCacheValue())->contains('CENTRAL'))->toBeTrue();

    tenancy()->initialize($tenant);

    // The 'foo' cache value in the tenant database should be 'TENANT'
    expect(cache('foo'))->toBe('TENANT');
    expect(str($databaseCacheValue())->contains('TENANT'))->toBeTrue();

    tenancy()->initialize($tenant2);

    // The 'foo' cache value in another tenant's database should be null
    expect(cache('foo'))->toBeNull();
    expect(DB::select('select * from cache'))->toHaveCount(0);
});
