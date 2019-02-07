<?php

namespace Stancl\Tenancy\DatabaseCreators;

use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Interfaces\DatabaseCreator;

class MySQLDatabaseCreator implements DatabaseCreator
{
    public function createDatabase(string $name): bool
    {
        return DB::statement("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
}
