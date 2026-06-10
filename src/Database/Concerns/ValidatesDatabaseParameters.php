<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use InvalidArgumentException;

/**
 * Provides methods to validate database parameters (e.g. database names, usernames, passwords)
 * before using them in SQL statements (or in file paths in the case of SQLiteDatabaseManager).
 *
 * Used where parameters can be provided by users, and where parameter binding cannot be used.
 *
 * @see \Stancl\Tenancy\Database\TenantDatabaseManagers\TenantDatabaseManager
 * @see \Stancl\Tenancy\Database\TenantDatabaseManagers\SQLiteDatabaseManager
 */
trait ValidatesDatabaseParameters
{
    /**
     * Characters allowed in parameters.
     *
     * Used as the default allowlist in validateParameter(), which validates non-password
     * parameters such as database names or usernames.
     *
     * Since non-password parameters don't need to use as many special characters, we use
     * a stricter allowlist here.
     */
    public static string $allowedParameterCharacters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-';

    /**
     * Characters allowed in database user passwords.
     *
     * The allowlist for passwords is less strict than for other parameters
     * because it's more common to use more special characters in passwords.
     */
    public static string $allowedPasswordCharacters = ' !#$%&()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[]^_abcdefghijklmnopqrstuvwxyz{|}~';

    /**
     * Ensure that parameter (database name, username, etc.)
     * only contains allowed characters before being used in SQL statements
     * (or paths in the case of SQLiteDatabaseManager).
     *
     * By default, only the characters in allowedParameterCharacters() are allowed.
     *
     * @throws InvalidArgumentException
     */
    protected function validateParameter(mixed $parameter, string|null $allowedCharacters = null): void
    {
        if (is_null($parameter)) {
            throw new InvalidArgumentException('Parameter cannot be null.');
        }

        if (is_numeric($parameter)) {
            $parameter = (string) $parameter;
        }

        if (! is_string($parameter)) {
            // E.g. if a parameter is retrieved from the config, it isn't necessarily a string
            throw new InvalidArgumentException('Parameter has to be a string.');
        }

        $allowedCharacters ??= static::$allowedParameterCharacters;

        foreach (str_split($parameter) as $character) {
            if (! str_contains($allowedCharacters, $character)) {
                throw new InvalidArgumentException("Forbidden character '{$character}' in parameter.");
            }
        }
    }

    /**
     * Ensure password only contains allowed characters (allowedPasswordCharacters())
     * before being used in SQL statements.
     *
     * Used in permission controlled managers as a shorthand for calling validateParameter()
     * with the less strict allowlist to validate database user passwords.
     *
     * @throws InvalidArgumentException
     */
    protected function validatePassword(string|null $password): void
    {
        if (is_null($password)) {
            throw new InvalidArgumentException('Parameter cannot be null.');
        }

        $this->validateParameter($password, allowedCharacters: static::$allowedPasswordCharacters);
    }
}
