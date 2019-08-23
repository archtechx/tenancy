<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Interfaces;

interface StorageDriver
{
    public function identifyTenant(string $domain): array;

    /** @return array[] */
    public function getAllTenants(array $uuids = []): array;

    public function getTenantById(string $uuid, array $fields = []): array;

    public function getTenantIdByDomain(string $domain): ?string;

    public function createTenant(string $domain, string $uuid): array;

    public function deleteTenant(string $uuid): bool;

    public function get(string $uuid, string $key);

    public function getMany(string $uuid, array $keys): array;

    public function put(string $uuid, string $key, $value);

    public function putMany(string $uuid, array $values): array;
}
