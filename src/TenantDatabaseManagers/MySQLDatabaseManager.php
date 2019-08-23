<?php

declare(strict_types=1);

namespace Stancl\Tenancy\TenantDatabaseManagers;

use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Interfaces\TenantDatabaseManager;

class MySQLDatabaseManager implements TenantDatabaseManager
{
    public function createDatabase(string $name): bool
    {
        return DB::statement("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    public function deleteDatabase(string $name): bool
    {
        return DB::statement("DROP DATABASE `$name`");
    }
}
