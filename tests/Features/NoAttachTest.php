<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Features\DisallowSqliteAttach;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;
use Stancl\Tenancy\Tests\Etc\Tenant;
use function Stancl\Tenancy\Tests\pest;

test('sqlite ATTACH statements can be blocked', function (bool $disallow) {
    if (php_uname('m') == 'aarch64') {
        // Escape testbench prison. Can't hardcode /var/www/html/extensions/... here
        // since GHA doesn't mount the filesystem on the container's workdir
        DisallowSqliteAttach::$extensionPath = realpath(base_path('../../../../extensions/lib/arm/noattach.so'));
    } else {
        DisallowSqliteAttach::$extensionPath = realpath(base_path('../../../../extensions/lib/noattach.so'));
    }

    if ($disallow) config(['tenancy.features' => [DisallowSqliteAttach::class]]);

    config(['tenancy.bootstrappers' => [DatabaseTenancyBootstrapper::class]]);
    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);

    Event::listen(TenantCreated::class, JobPipeline::make([
        CreateDatabase::class,
        MigrateDatabase::class,
    ])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    $tempdb1 = tempnam(sys_get_temp_dir(), 'tenancy_attach_test');
    $tempdb2 = tempnam(sys_get_temp_dir(), 'tenancy_attach_test');
    register_shutdown_function(fn () => @unlink($tempdb1));
    register_shutdown_function(fn () => @unlink($tempdb2));

    config(['database.connections.foo' => ['driver' => 'sqlite', 'database' => $tempdb1]]);
    config(['database.connections.bar' => ['driver' => 'sqlite', 'database' => $tempdb2]]);

    DB::connection('bar')->statement('CREATE TABLE secrets (key, value)');
    DB::connection('bar')->statement('INSERT INTO secrets (key, value) VALUES ("secret_foo", "secret_bar")');

    Route::post('/central-sqli', function () {
        DB::connection('foo')->select(request('q1'));
        return json_encode(DB::connection('foo')->select(request('q2')));
    });

    Route::middleware(InitializeTenancyByPath::class)->post('/{tenant}/tenant-sqli', function () {
        DB::select(request('q1'));
        return json_encode(DB::select(request('q2')));
    });

    tenancy(); // trigger features: todo@samuel remove after feature refactor

    if ($disallow) {
        expect(fn () => pest()->post('/central-sqli', [
            'q1' => 'ATTACH DATABASE "' . $tempdb2 . '" as bar',
            'q2' => 'SELECT * from bar.secrets',
        ])->json())->toThrow(QueryException::class, 'not authorized');
    } else {
        expect(pest()->post('/central-sqli', [
            'q1' => 'ATTACH DATABASE "' . $tempdb2 . '" as bar',
            'q2' => 'SELECT * from bar.secrets',
        ])->json()[0])->toBe([
            'key' => 'secret_foo',
            'value' => 'secret_bar',
        ]);
    }

    $tenant = Tenant::create([
        'tenancy_db_connection' => 'sqlite',
    ]);

    if ($disallow) {
        expect(fn () => pest()->post($tenant->id . '/tenant-sqli', [
            'q1' => 'ATTACH DATABASE "' . $tempdb2 . '" as baz',
            'q2' => 'SELECT * from bar.secrets',
        ])->json())->toThrow(QueryException::class, 'not authorized');
    } else {
        expect(pest()->post($tenant->id . '/tenant-sqli', [
            'q1' => 'ATTACH DATABASE "' . $tempdb2 . '" as baz',
            'q2' => 'SELECT * from baz.secrets',
        ])->json()[0])->toBe([
            'key' => 'secret_foo',
            'value' => 'secret_bar',
        ]);
    }
})->with([true, false]);
