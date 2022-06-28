<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Contracts;
use Stancl\Tenancy\Contracts\UniqueIdentifierGenerator;
use Stancl\Tenancy\Database\TenantCollection;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Stancl\Tenancy\UUIDGenerator;

uses(Stancl\Tenancy\Tests\TestCase::class);

test('created event is dispatched', function () {
    Event::fake([TenantCreated::class]);

    Event::assertNotDispatched(TenantCreated::class);

    Tenant::create();

    Event::assertDispatched(TenantCreated::class);
});

test('current tenant can be resolved from service container using typehint', function () {
    $tenant = Tenant::create();

    tenancy()->initialize($tenant);

    expect(app(Contracts\Tenant::class)->id)->toBe($tenant->id);

    tenancy()->end();

    expect(app(Contracts\Tenant::class))->toBe(null);
});

test('id is generated when no id is supplied', function () {
    config(['tenancy.id_generator' => UUIDGenerator::class]);

    $this->mock(UUIDGenerator::class, function ($mock) {
        return $mock->shouldReceive('generate')->once();
    });

    $tenant = Tenant::create();

    $this->assertNotNull($tenant->id);
});

test('autoincrement ids are supported', function () {
    Schema::drop('domains');
    Schema::table('tenants', function (Blueprint $table) {
        $table->bigIncrements('id')->change();
    });

    unset(app()[UniqueIdentifierGenerator::class]);

    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();

    expect($tenant1->id)->toBe(1);
    expect($tenant2->id)->toBe(2);
});

test('custom tenant model can be used', function () {
    $tenant = MyTenant::create();

    tenancy()->initialize($tenant);

    expect(tenant() instanceof MyTenant)->toBeTrue();
});

test('custom tenant model that doesnt extend vendor tenant model can be used', function () {
    $tenant = AnotherTenant::create([
        'id' => 'acme',
    ]);

    tenancy()->initialize($tenant);

    expect(tenant() instanceof AnotherTenant)->toBeTrue();
});

test('tenant can be created even when we are in another tenants context', function () {
    config(['tenancy.bootstrappers' => [
        DatabaseTenancyBootstrapper::class,
    ]]);

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function ($event) {
        return $event->tenant;
    })->toListener());

    $tenant1 = Tenant::create([
        'id' => 'foo',
        'tenancy_db_name' => 'db' . Str::random(16),
    ]);

    tenancy()->initialize($tenant1);

    $tenant2 = Tenant::create([
        'id' => 'bar',
        'tenancy_db_name' => 'db' . Str::random(16),
    ]);

    tenancy()->end();

    expect(Tenant::count())->toBe(2);
});

test('the model uses tenant collection', function () {
    Tenant::create();
    Tenant::create();

    expect(Tenant::count())->toBe(2);
    expect(Tenant::all() instanceof TenantCollection)->toBeTrue();
});

test('a command can be run on a collection of tenants', function () {
    Tenant::create([
        'id' => 't1',
        'foo' => 'bar',
    ]);
    Tenant::create([
        'id' => 't2',
        'foo' => 'bar',
    ]);

    Tenant::all()->runForEach(function ($tenant) {
        $tenant->update([
            'foo' => 'xyz',
        ]);
    });

    expect(Tenant::find('t1')->foo)->toBe('xyz');
    expect(Tenant::find('t2')->foo)->toBe('xyz');
});

// Helpers
function getTenantKeyName(): string
{
    return 'id';
}

function getTenantKey()
{
    return test()->getAttribute('id');
}

function run(callable $callback)
{
    $callback();
}

function getInternal(string $key)
{
    return test()->$key;
}

function setInternal(string $key, $value)
{
    test()->$key = $value;
}
