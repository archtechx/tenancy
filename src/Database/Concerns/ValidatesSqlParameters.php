<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use InvalidArgumentException;

// todo@validation this trait's name might be a bit misleading
// it suggests validating parameters for SQL statements, but it is also used in SQLiteDatabaseManager to validate the database file name
trait ValidatesSqlParameters
{
    /**
     * Characters allowed in the parameters.
     */
    protected static function parameterAllowlist(): string
    {
        return 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-';
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
                if (! str_contains(static::parameterAllowlist(), $char)) {
                    throw new InvalidArgumentException("Invalid character '{$char}' in SQL parameter: {$parameter}");
                }
            }
        }

        return $parameters;
    }
}
