<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;
use Stancl\Tenancy\Tests\Etc\Tenant;

uses(Stancl\Tenancy\Tests\TestCase::class);

afterEach(function () {
    DomainTenantResolver::$shouldCache = false;
});

test('tenants can be resolved using the cached resolver', function () {
    $tenant = Tenant::create();
    $tenant->domains()->create([
        'domain' => 'acme',
    ]);

    $this->assertTrue($tenant->is(app(DomainTenantResolver::class)->resolve('acme')));
    $this->assertTrue($tenant->is(app(DomainTenantResolver::class)->resolve('acme')));
});

test('the underlying resolver is not touched when using the cached resolver', function () {
    $tenant = Tenant::create();
    $tenant->domains()->create([
        'domain' => 'acme',
    ]);

    DB::enableQueryLog();

    DomainTenantResolver::$shouldCache = false;

    $this->assertTrue($tenant->is(app(DomainTenantResolver::class)->resolve('acme')));
    DB::flushQueryLog();
    $this->assertTrue($tenant->is(app(DomainTenantResolver::class)->resolve('acme')));
    $this->assertNotEmpty(DB::getQueryLog()); // not empty

    DomainTenantResolver::$shouldCache = true;

    $this->assertTrue($tenant->is(app(DomainTenantResolver::class)->resolve('acme')));
    DB::flushQueryLog();
    $this->assertTrue($tenant->is(app(DomainTenantResolver::class)->resolve('acme')));
    $this->assertEmpty(DB::getQueryLog()); // empty
});

test('cache is invalidated when the tenant is updated', function () {
    $tenant = Tenant::create();
    $tenant->createDomain([
        'domain' => 'acme',
    ]);

    DB::enableQueryLog();

    DomainTenantResolver::$shouldCache = true;

    $this->assertTrue($tenant->is(app(DomainTenantResolver::class)->resolve('acme')));
    DB::flushQueryLog();
    $this->assertTrue($tenant->is(app(DomainTenantResolver::class)->resolve('acme')));
    $this->assertEmpty(DB::getQueryLog()); // empty

    $tenant->update([
        'foo' => 'bar',
    ]);

    DB::flushQueryLog();
    $this->assertTrue($tenant->is(app(DomainTenantResolver::class)->resolve('acme')));
    $this->assertNotEmpty(DB::getQueryLog()); // not empty
});

test('cache is invalidated when a tenants domain is changed', function () {
    $tenant = Tenant::create();
    $tenant->createDomain([
        'domain' => 'acme',
    ]);

    DB::enableQueryLog();

    DomainTenantResolver::$shouldCache = true;

    $this->assertTrue($tenant->is(app(DomainTenantResolver::class)->resolve('acme')));
    DB::flushQueryLog();
    $this->assertTrue($tenant->is(app(DomainTenantResolver::class)->resolve('acme')));
    $this->assertEmpty(DB::getQueryLog()); // empty

    $tenant->createDomain([
        'domain' => 'bar',
    ]);

    DB::flushQueryLog();
    $this->assertTrue($tenant->is(app(DomainTenantResolver::class)->resolve('acme')));
    $this->assertNotEmpty(DB::getQueryLog()); // not empty

    DB::flushQueryLog();
    $this->assertTrue($tenant->is(app(DomainTenantResolver::class)->resolve('bar')));
    $this->assertNotEmpty(DB::getQueryLog()); // not empty
});
