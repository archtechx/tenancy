<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use InvalidArgumentException;

/**
 * Provides methods to validate database parameters (e.g. database names, usernames, passwords)
 * before using them in SQL statements (or in file paths in the case of SQLiteDatabaseManager).
 *
 * Used where parameters can be provided by users, and where parameter binding isn't possible.
 *
 * @mixin \Stancl\Tenancy\Database\TenantDatabaseManagers\TenantDatabaseManager
 * @mixin \Stancl\Tenancy\Database\TenantDatabaseManagers\SQLiteDatabaseManager
 */
trait ValidatesDatabaseParameters
{
    /**
     * Characters allowed in the parameters.
     */
    protected static function parameterAllowlist(): string
    {
        return 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-';
    }

    /**
     * Characters allowed in database user passwords.
     *
     * Passwords are always quoted in the SQL statements, so it's safe
     * to allow a wider range of characters, as long as it doesn't include
     * characters that can break out of the quoted SQL strings (so e.g.
     * ', ", \, and ` aren't allowed).
     */
    protected static function passwordAllowlist(): string
    {
        return ' !#$%&()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[]^_abcdefghijklmnopqrstuvwxyz{|}~';
    }

    /**
     * Ensure that parameters (database names, usernames, etc.)
     * only contain allowed characters before used in SQL statements
     * (or file names in the case of SQLiteDatabaseManager).
     *
     * By default, only the characters in static::parameterAllowlist() are allowed.
     *
     * @throws InvalidArgumentException
     */
    protected function validateParameter(string|array|null $parameters, string|null $allowlist = null): string|array|null
    {
        if (is_null($parameters)) {
            // Return null if there's nothing to validate
            // (e.g. when $databaseConfig->getUsername() of an
            // improperly created tenant is passed).
            return null;
        }

        $allowlist = $allowlist ?? static::parameterAllowlist();

        foreach ((array) $parameters as $parameter) {
            foreach (str_split($parameter) as $char) {
                if (! str_contains($allowlist, $char)) {
                    throw new InvalidArgumentException("Invalid character '{$char}' in parameter: {$parameter}");
                }
            }
        }

        return $parameters;
    }

    /**
     * Ensure password only contains allowed characters before used in SQL statements.
     *
     * Used as a shorthand for calling validateParameter() with the less strict allowlist.
     *
     * @throws InvalidArgumentException
     */
    protected function validatePassword(string|null $password): string|null
    {
        return $this->validateParameter($password, static::passwordAllowlist());
    }
}
