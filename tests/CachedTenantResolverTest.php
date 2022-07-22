<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;
use Stancl\Tenancy\Tests\Etc\Tenant;

afterEach(function () {
    DomainTenantResolver::$shouldCache = false;
});

test('tenants can be resolved using the cached resolver', function () {
    $tenant = Tenant::create();
    $tenant->domains()->create([
        'domain' => 'acme',
    ]);

    expect($tenant->is(app(DomainTenantResolver::class)->resolve('acme')))->toBeTrue()->toBeTrue();
});

test('the underlying resolver is not touched when using the cached resolver', function () {
    $tenant = Tenant::create();
    $tenant->domains()->create([
        'domain' => 'acme',
    ]);

    DB::enableQueryLog();

    DomainTenantResolver::$shouldCache = false;

    expect($tenant->is(app(DomainTenantResolver::class)->resolve('acme')))->toBeTrue();
    DB::flushQueryLog();
    expect($tenant->is(app(DomainTenantResolver::class)->resolve('acme')))->toBeTrue();
    pest()->assertNotEmpty(DB::getQueryLog()); // not empty

    DomainTenantResolver::$shouldCache = true;

    expect($tenant->is(app(DomainTenantResolver::class)->resolve('acme')))->toBeTrue();
    DB::flushQueryLog();
    expect($tenant->is(app(DomainTenantResolver::class)->resolve('acme')))->toBeTrue();
    expect(DB::getQueryLog())->toBeEmpty(); // empty
});

test('cache is invalidated when the tenant is updated', function () {
    $tenant = Tenant::create();
    $tenant->createDomain([
        'domain' => 'acme',
    ]);

    DB::enableQueryLog();

    DomainTenantResolver::$shouldCache = true;

    expect($tenant->is(app(DomainTenantResolver::class)->resolve('acme')))->toBeTrue();
    DB::flushQueryLog();
    expect($tenant->is(app(DomainTenantResolver::class)->resolve('acme')))->toBeTrue();
    expect(DB::getQueryLog())->toBeEmpty(); // empty

    $tenant->update([
        'foo' => 'bar',
    ]);

    DB::flushQueryLog();
    expect($tenant->is(app(DomainTenantResolver::class)->resolve('acme')))->toBeTrue();
    pest()->assertNotEmpty(DB::getQueryLog()); // not empty
});

test('cache is invalidated when a tenants domain is changed', function () {
    $tenant = Tenant::create();
    $tenant->createDomain([
        'domain' => 'acme',
    ]);

    DB::enableQueryLog();

    DomainTenantResolver::$shouldCache = true;

    expect($tenant->is(app(DomainTenantResolver::class)->resolve('acme')))->toBeTrue();
    DB::flushQueryLog();
    expect($tenant->is(app(DomainTenantResolver::class)->resolve('acme')))->toBeTrue();
    expect(DB::getQueryLog())->toBeEmpty(); // empty

    $tenant->createDomain([
        'domain' => 'bar',
    ]);

    DB::flushQueryLog();
    expect($tenant->is(app(DomainTenantResolver::class)->resolve('acme')))->toBeTrue();
    pest()->assertNotEmpty(DB::getQueryLog()); // not empty

    DB::flushQueryLog();
    expect($tenant->is(app(DomainTenantResolver::class)->resolve('bar')))->toBeTrue();
    pest()->assertNotEmpty(DB::getQueryLog()); // not empty
});
