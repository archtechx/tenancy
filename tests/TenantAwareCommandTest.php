<?php

declare(strict_types=1);

use Illuminate\Console\CacheCommandMutex;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Tests\Etc\Console\IsolatableTenantAwareCommand;
use Stancl\Tenancy\Tests\Etc\Tenant;
use function Stancl\Tenancy\Tests\pest;

test('commands run globally are tenant aware and return valid exit code', function () {
    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();
    Artisan::call('tenants:migrate', [
        '--tenants' => [$tenant1['id'], $tenant2['id']],
    ]);

    pest()->artisan('user:add')
        ->assertExitCode(0);

    expect(tenancy()->initialized)->toBeFalse();

    tenancy()->initialize($tenant1);
    pest()->assertNotEmpty(DB::table('users')->get());
    tenancy()->end();

    tenancy()->initialize($tenant2);
    pest()->assertNotEmpty(DB::table('users')->get());
    tenancy()->end();
});

test('failing a command manually prints the error and returns a failure exit code', function () {
    $tenant = Tenant::create();

    pest()->artisan('tenant-aware:fail')
        ->expectsOutputToContain('Failed for tenant ' . $tenant->getTenantKey())
        ->assertExitCode(1);

    expect(tenancy()->initialized)->toBeFalse();
});

test('invokable commands are tenant aware', function () {
    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();

    pest()->artisan('tenant-aware:isolatable')
        ->expectsOutputToContain('Ran for tenant ' . $tenant1->getTenantKey())
        ->expectsOutputToContain('Ran for tenant ' . $tenant2->getTenantKey())
        ->assertExitCode(0);

    expect(tenancy()->initialized)->toBeFalse();
});

test('isolated commands do not run for any tenant if another instance is running', function () {
    Tenant::create();
    Tenant::create();

    $mutex = app(CacheCommandMutex::class);
    $command = app(IsolatableTenantAwareCommand::class);

    // Simulate another instance of the command running
    $mutex->create($command);

    // --isolated can take the exit code to return if the command is already running (0 by default)
    expect(Artisan::call('tenant-aware:isolatable', ['--isolated' => 2]))->toBe(2);

    $output = Artisan::output();

    // The lock is taken once for the whole command rather than for each tenant,
    // so with two tenants, the command reports that it's already running only once
    expect(substr_count($output, 'The [tenant-aware:isolatable] command is already running.'))->toBe(1);
    expect($output)->not->toContain('Ran for tenant');

    $mutex->forget($command);

    pest()->artisan('tenant-aware:isolatable', ['--isolated' => 2])
        ->expectsOutputToContain('Ran for tenant')
        ->assertExitCode(0);

    // The mutex is released once the command finishes
    expect($mutex->exists($command))->toBeFalse();
    expect(tenancy()->initialized)->toBeFalse();
});
