<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Contracts;

use Illuminate\Database\Connection;

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

    /**
     * Get the schema/database name that the given connection points to.
     *
     * In database managers, this should return the *database* name of the passed connection,
     * while in schema managers, this should return the *schema* name of the passed connection.
     */
    public function getCurrentDatabaseName(Connection $connection): string;
}
