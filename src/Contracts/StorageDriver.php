<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts;

use Stancl\Tenancy\Tenant;

// todo this class now manages types (json encoding)
// make sure ids are not json encoded

interface StorageDriver
{
    public function createTenant(Tenant $tenant): void;

    public function updateTenant(Tenant $tenant): void;

    public function deleteTenant(Tenant $tenant): void;

    /**
     * Find a tenant using an id.
     *
     * @param string $id
     * @return Tenant
     * @throws \Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedException
     */
    public function findById(string $id): Tenant;

    /**
     * Find a tenant using a domain name.
     *
     * @param string $domain
     * @return Tenant
     */
    public function findByDomain(string $domain): Tenant;

    /**
     * Get all tenants.
     *
     * @param string[] $ids
     * @return Tenant[]
     */
    public function all(array $ids = []): array;

    /**
     * Check if a tenant can be created.
     *
     * @param Tenant $tenant
     * @return true|TenantCannotBeCreatedException
     */
    public function canCreateTenant(Tenant $tenant);

    /**
     * Get a value from storage.
     *
     * @param string $key
     * @param ?Tenant $tenant
     * @return mixed
     */
    public function get(string $key, Tenant $tenant = null);

    /**
     * Get multiple values from storage.
     *
     * @param array $keys
     * @param ?Tenant $tenant
     * @return void
     */
    public function getMany(array $keys, Tenant $tenant = null);

    /**
     * Put a value into storage.
     *
     * @param string $key
     * @param mixed $value
     * @param ?Tenant $tenant
     * @return void
     */
    public function put(string $key, $value, Tenant $tenant = null): void;

    /**
     * Put multiple values into storage.
     *
     * @param mixed[string] $kvPairs
     * @param ?Tenant $tenant
     * @return void
     */
    public function putMany(array $kvPairs, Tenant $tenant = null): void;
}
