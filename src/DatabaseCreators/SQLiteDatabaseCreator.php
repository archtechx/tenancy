<?php

namespace Stancl\Tenancy\DatabaseCreators;

use Stancl\Tenancy\Interfaces\DatabaseCreator;

class SQLiteDatabaseCreator implements DatabaseCreator
{
    public function createDatabase(string $name): bool
    {
        fclose(fopen(database_path($name), 'w'));

        return true;
    }
}
