<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Interfaces;

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
}
