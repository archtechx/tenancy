<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\TenantDatabaseManagers;

use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;

class PostgreSQLDatabaseManager extends TenantDatabaseManager
{
    public function createDatabase(TenantWithDatabase $tenant): bool
    {
        $database = $this->quoteIdentifier($tenant->database()->getName());

        return $this->connection()->statement("CREATE DATABASE {$database} WITH TEMPLATE=template0");
    }

    public function deleteDatabase(TenantWithDatabase $tenant): bool
    {
        $database = $this->quoteIdentifier($tenant->database()->getName());

        return $this->connection()->statement("DROP DATABASE {$database}");
    }

    public function databaseExists(string $name): bool
    {
        return (bool) $this->connection()->selectOne(
            'SELECT datname FROM pg_database WHERE datname = ? LIMIT 1',
            [$name]
        );
    }

    protected function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
