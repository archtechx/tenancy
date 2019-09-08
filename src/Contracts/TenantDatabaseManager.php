<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts;

interface TenantDatabaseManager
{
    /**
     * Create a database.
     *
     * @param  string $name Name of the database.
     * @return bool
     */
    public function createDatabase(string $name): bool;

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
}
