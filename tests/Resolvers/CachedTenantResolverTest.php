<?php

declare(strict_types=1);

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Stancl\Tenancy\Contracts\TenantResolver;
use Stancl\Tenancy\Resolvers\CachedTenantResolver;
use Stancl\Tenancy\Tests\Etc\Tenant;

it('uses the underlying resolver if cache is stale', function () {
    $underlying = Mockery::mock(TenantResolver::class);
    $cache = new Repository($store = Mockery::mock(Store::class));

    $args = [
        'id' => 1,
    ];

    $resolver = new CachedTenantResolver(
        tenantResolver: $underlying,
        cache: $cache,
        prefix: '_tenant_resolver',
    );

    $store->expects('get')->withAnyArgs()->andReturnNull();
    $underlying->expects('resolve')->andReturn($tenant = new Tenant());
    $store->expects('put')->withSomeOfArgs($tenant);

    expect($resolver->resolve($args))->toBe($tenant);
});

it('skips the underlying resolver if cache is valid', function () {
    $underlying = Mockery::mock(TenantResolver::class);
    $cache =  new Repository($store = Mockery::mock(Store::class));

    $args = [
        'id' => 1,
    ];

    $resolver = new CachedTenantResolver(
        tenantResolver: $underlying,
        cache: $cache,
        prefix: '_tenant_resolver',
    );

    $cache->expects('get')->withAnyArgs()->andReturn($tenant = new Tenant());
    $underlying->expects('resolve')->never();

    expect($resolver->resolve($args))->toBe($tenant);
});
