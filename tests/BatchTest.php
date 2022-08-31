<?php

use Illuminate\Bus\BatchRepository;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Bootstrappers\BatchTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Tests\Etc\Tenant;

beforeEach(function () {
    $this->app->singleton(BatchTenancyBootstrapper::class);

    config([
        'tenancy.bootstrappers' => [
            DatabaseTenancyBootstrapper::class,
            BatchTenancyBootstrapper::class,
        ],
    ]);

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
});

test('batch repository is set to tenant connection and reverted', function () {
    $tenant = Tenant::create();

    expect(getBatchRepositoryConnectionName())->toBe('central');

    tenancy()->initialize($tenant);

    expect(getBatchRepositoryConnectionName())->toBe('tenant');

    tenancy()->end();

    expect(getBatchRepositoryConnectionName())->toBe('central');
})->skip(fn() => version_compare(app()->version(), '8.0', '<'), 'Job batches are only supported in Laravel 8+');

function getBatchRepositoryConnectionName()
{
    return app(BatchRepository::class)->getConnection()->getName();
}
