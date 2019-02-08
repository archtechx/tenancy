<?php

namespace Stancl\Tenancy\Interfaces;

interface TenantDatabaseManager
{
    /**
     * Create a database.
     *
     * @param  string $name Name of the database.
     * @return boolean
     */
    public function createDatabase(string $name): bool;

    /**
     * Delete a database.
     *
     * @param  string $name Name of the database.
     * @return boolean
     */
    public function deleteDatabase(string $name): bool;
}
