<?php

use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedOnDomainException;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Stancl\Tenancy\Tests\Repository\InMemoryTenantRepository;

beforeEach(function () {
    $this->repository = new InMemoryTenantRepository();

    $this->tenant = new Tenant();
    $this->tenant->id = 1;

    $this->repository->store($this->tenant);
});

it('resolves the tenant', function () {
    $resolver = new DomainTenantResolver(
        tenantRepository: $this->repository,
    );

    $result = $resolver->resolve(id: 1);

    expect($result)->toBe($this->tenant);
});

it('throws when unable to find tenant', function () {
    $resolver = new DomainTenantResolver(
        tenantRepository: $this->repository,
    );

    $resolver->resolve('foo');
})->throws(TenantCouldNotBeIdentifiedOnDomainException::class);
