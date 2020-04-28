<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts;

use Stancl\Tenancy\Tenant;

interface TenantDatabaseManager
{
    /**
     * Create a database.
     *
     * @param string $name Name of the database.
     * @param Tenant $tenant
     * @return bool
     */
    public function createDatabase(string $name, Tenant $tenant): bool;

    /**
     * Delete a database.
     *
     * @param  string $name Name of the database.
     * @return bool
     */
    public function deleteDatabase(string $name): bool;

    /**
     * Does a database exist.
     *
     * @param string $name
     * @return bool
     */
    public function databaseExists(string $name): bool;

    /**
     * Override the base connection.
     *
     * @param Tenant $tenant
     * @param array $baseConfiguration
     * @return array
     */
    public function createDatabaseConnection(Tenant $tenant, array $baseConfiguration): array;
}
