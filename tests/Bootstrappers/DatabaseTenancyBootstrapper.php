<?php

use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Tests\Etc\Tenant;

use function Stancl\Tenancy\Tests\pest;

beforeEach(function () {
    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
});

test('database tenancy bootstrapper throws an exception if DATABASE_URL is set', function (string|null $databaseUrl) {
    if ($databaseUrl) {
        config(['database.connections.central.url' => $databaseUrl]);

        pest()->expectException(Exception::class);
    }

    config(['tenancy.bootstrappers' => [DatabaseTenancyBootstrapper::class]]);

    $tenant1 = Tenant::create();

    pest()->artisan('tenants:migrate');

    tenancy()->initialize($tenant1);

    expect(true)->toBe(true);
})->with(['abc.us-east-1.rds.amazonaws.com', null]);

