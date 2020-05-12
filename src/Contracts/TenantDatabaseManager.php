<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts;

interface TenantDatabaseManager
{
    /**
     * Create a database.
     */
    public function createDatabase(TenantWithDatabase $tenant): bool;

    /**
     * Delete a database.
     */
    public function deleteDatabase(TenantWithDatabase $tenant): bool;

    /**
     * Does a database exist.
     *
     * @param string $name
     * @return bool
     */
    public function databaseExists(string $name): bool;

    /**
     * Make a DB connection config array.
     *
     * @param array $baseConfig
     * @param string $databaseName
     * @return array
     */
    public function makeConnectionConfig(array $baseConfig, string $databaseName): array;
}
