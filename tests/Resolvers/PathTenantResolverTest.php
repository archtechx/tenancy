<?php

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedByPathException;
use Stancl\Tenancy\Resolvers\PathTenantResolver;
use Stancl\Tenancy\Tests\Repository\InMemoryTenantRepository;

beforeEach(function () {
    $this->repository = new InMemoryTenantRepository();

    $this->tenant = new Tenant();
    $this->tenant->id = 1;

    $this->repository->store($this->tenant);
});

it('resolves the tenant from path', function () {
    $resolver = new PathTenantResolver(
        tenantRepository: $this->repository,
    );

    $route = (new Route('get', '/{tenant}/foo', fn () => null))
        ->bind(Request::create('/1/foo'));

    $result = $resolver->resolve($route);

    expect($result)->toBe($this->tenant);
});

it('throws when unable to find tenant', function () {
    $resolver = new PathTenantResolver(
        tenantRepository: new InMemoryTenantRepository(),
    );

    $route = (new Route('GET', '/{tenant}/foo', fn () => null))
        ->bind(Request::create('/2/foo'));

    $resolver->resolve($route);
})->throws(TenantCouldNotBeIdentifiedByPathException::class);
