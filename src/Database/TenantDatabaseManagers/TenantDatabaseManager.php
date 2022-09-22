<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\TenantDatabaseManagers;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Database\Contracts\TenantDatabaseManager as Contract;
use Stancl\Tenancy\Database\Exceptions\NoConnectionSetException;

abstract class TenantDatabaseManager implements Contract // todo better naming?
{
    /** The database connection to the server. */
    protected string $connection;

    protected function database(): Connection
    {
        if (! isset($this->connection)) {
            throw new NoConnectionSetException(static::class);
        }

        return DB::connection($this->connection);
    }

    public function setConnection(string $connection): void
    {
        $this->connection = $connection;
    }

    public function makeConnectionConfig(array $baseConfig, string $databaseName): array
    {
        $baseConfig['database'] = $databaseName;

        return $baseConfig;
    }
}
