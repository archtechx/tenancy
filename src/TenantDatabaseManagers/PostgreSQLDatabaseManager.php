<?php

declare(strict_types=1);

namespace Stancl\Tenancy\TenantDatabaseManagers;

use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Interfaces\TenantDatabaseManager;

class PostgreSQLDatabaseManager implements TenantDatabaseManager
{
    public function createDatabase(string $name): bool
    {
        return DB::statement("CREATE DATABASE \"$name\" WITH TEMPLATE=template0");
    }

    public function deleteDatabase(string $name): bool
    {
        return DB::statement("DROP DATABASE \"$name\"");
    }
}
