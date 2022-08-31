<?php

use Stancl\Tenancy\Enums\LogMode;
use Stancl\Tenancy\Events\EndingTenancy;
use Stancl\Tenancy\Events\InitializingTenancy;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Tests\Etc\Tenant;

test('tenancy can log events silently', function () {
    tenancy()->log(LogMode::SILENT);

    $tenant = Tenant::create();

    tenancy()->initialize($tenant);

    tenancy()->end();

    assertTenancyInitializedAndEnded(tenancy()->getLog(), $tenant);
});

test('tenancy logs event silently by default', function () {
    tenancy()->log();

    expect(tenancy()->logMode())->toBe(LogMode::SILENT);
});

test('the log can be dumped', function (string $method) {
    tenancy()->log();

    $tenant = Tenant::create();

    tenancy()->initialize($tenant);

    tenancy()->end();

    $output = [];
    tenancy()->$method(function ($data) use (&$output) {
        $output = $data;
    });

    assertTenancyInitializedAndEnded($output, $tenant);
})->with([
    'dump',
    'dd',
]);

test('tenancy can log events immediately', function () {
    // todo implement
    pest()->markTestIncomplete();
});

// todo test the different behavior of the methods in different contexts, or get rid of the logic and simplify it

function assertTenancyInitializedAndEnded(array $log, Tenant $tenant): void
{
    expect($log)->toHaveCount(4);

    expect($log[0]['event'])->toBe(InitializingTenancy::class);
    expect($log[0]['tenant'])->toBe($tenant);
    expect($log[1]['event'])->toBe(TenancyInitialized::class);
    expect($log[1]['tenant'])->toBe($tenant);

    expect($log[2]['event'])->toBe(EndingTenancy::class);
    expect($log[2]['tenant'])->toBe($tenant);
    expect($log[3]['event'])->toBe(TenancyEnded::class);
    expect($log[3]['tenant'])->toBe($tenant);
}
