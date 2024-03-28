<?php

use Illuminate\Bus\BatchRepository;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Bootstrappers\JobBatchBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Tests\Etc\Tenant;

beforeEach(function () {
    config([
        'tenancy.bootstrappers' => [
            DatabaseTenancyBootstrapper::class,
            JobBatchBootstrapper::class,
        ],
    ]);

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
});

test('batch repository is set to tenant connection and reverted', function () {
    withTenantDatabases();

    $tenant = Tenant::create();
    $tenant2 = Tenant::create();

    expect(getBatchRepositoryConnectionName())->toBe('central');

    tenancy()->initialize($tenant);
    expect(getBatchRepositoryConnectionName())->toBe('tenant');

    tenancy()->initialize($tenant2);
    expect(getBatchRepositoryConnectionName())->toBe('tenant');

    tenancy()->end();
    expect(getBatchRepositoryConnectionName())->toBe('central');
});

function getBatchRepositoryConnectionName()
{
    return app(BatchRepository::class)->getConnection()->getName();
}
