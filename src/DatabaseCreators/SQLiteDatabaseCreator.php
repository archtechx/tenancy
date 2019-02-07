<?php

namespace Stancl\Tenancy\DatabaseCreators;

use Stancl\Tenancy\Interfaces\DatabaseCreator;

class SQLiteDatabaseCreator implements DatabaseCreator
{
    public function createDatabase(string $name): bool
    {
        return fclose(fopen(database_path($name), 'w'));
    }
}
