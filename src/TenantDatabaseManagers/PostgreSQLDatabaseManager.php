<?php

namespace Stancl\Tenancy\TenantDatabaseManagers;

use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Interfaces\TenantDatabaseManager;

class PostgreSQLDatabaseManager implements TenantDatabaseManager
{
    public function createDatabase(string $name): bool
    {
        return DB::statement("CREATE DATABASE `$name` WITH ENCODING 'UTF8' LC_COLLATE = 'en_US.UTF-8' LC_CTYPE = 'en_US.UTF-8'");
    }

    public function deleteDatabase(string $name): bool
    {
        return DB::statement("DROP DATABASE `$name`");
    }
}
