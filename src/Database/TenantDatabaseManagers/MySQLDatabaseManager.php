<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\TenantDatabaseManagers;

use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;

class MySQLDatabaseManager extends TenantDatabaseManager
{
    public function createDatabase(TenantWithDatabase $tenant): bool
    {
        $database = $this->quoteIdentifier($tenant->database()->getName());
        $charset = $this->connection()->getConfig('charset');
        $collation = $this->connection()->getConfig('collation');

        return $this->connection()->statement("CREATE DATABASE {$database} CHARACTER SET `$charset` COLLATE `$collation`");
    }

    public function deleteDatabase(TenantWithDatabase $tenant): bool
    {
        $database = $this->quoteIdentifier($tenant->database()->getName());

        return $this->connection()->statement("DROP DATABASE {$database}");
    }

    public function databaseExists(string $name): bool
    {
        return (bool) $this->connection()->selectOne(
            'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ? LIMIT 1',
            [$name]
        );
    }

    protected function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
