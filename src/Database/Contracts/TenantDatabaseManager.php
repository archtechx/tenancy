<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Contracts;

interface TenantDatabaseManager
{
    /** Create a database. */
    public function createDatabase(TenantWithDatabase $tenant): bool;

    /** Delete a database. */
    public function deleteDatabase(TenantWithDatabase $tenant): bool;

    /** Does a database exist? */
    public function databaseExists(string $name): bool;

    /** Construct a DB connection config array. */
    public function makeConnectionConfig(array $baseConfig, string $databaseName): array;
}
