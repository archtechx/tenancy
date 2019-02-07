<?php

namespace Stancl\Tenancy\Interfaces;

interface DatabaseCreator
{
    /**
     * Create a database.
     *
     * @param string $name Name of the database.
     * @return void
     */
    public function createDatabase(string $name): bool;
}
