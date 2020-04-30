<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts;

use Stancl\Tenancy\Tenant;

interface TenantDatabaseManager
{
    /**
     * Return the config key that separates databases (e.g. 'database' or 'schema').
     *
     * @return string
     */
    public function getSeparator(): string;

    /**
     * Create a database.
     */
    public function createDatabase(Tenant $tenant): bool;

    /**
     * Delete a database.
     */
    public function deleteDatabase(Tenant $tenant): bool;

    /**
     * Does a database exist.
     *
     * @param string $name
     * @return bool
     */
    public function databaseExists(string $name): bool;
}
