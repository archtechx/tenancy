<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\TenantDatabaseManagers;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Stancl\Tenancy\Database\Contracts\StatefulTenantDatabaseManager;
use Stancl\Tenancy\Database\Exceptions\NoConnectionSetException;

abstract class TenantDatabaseManager implements StatefulTenantDatabaseManager
{
    /** Characters allowed in SQL identifiers (database names, usernames, schema names, etc.). */
    public static string $allowlist = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-';

    /** The database connection to the server. */
    protected string $connection;

    public function connection(): Connection
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

    /**
     * Validate that parameters (database names, usernames, etc.)
     * contain only allowed characters before used in SQL statements.
     *
     * @throws InvalidArgumentException
     */
    protected function validateParameter(string|array $parameters): string|array
    {
        foreach ((array) $parameters as $parameter) {
            foreach (str_split($parameter) as $char) {
                if (! str_contains(static::$allowlist, $char)) {
                    throw new InvalidArgumentException("Invalid character '{$char}' in SQL parameter: {$parameter}");
                }
            }
        }

        return $parameters;
    }
}
