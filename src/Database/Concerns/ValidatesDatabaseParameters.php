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
 * @mixin \Stancl\Tenancy\Database\TenantDatabaseManagers\TenantDatabaseManager
 * @mixin \Stancl\Tenancy\Database\TenantDatabaseManagers\SQLiteDatabaseManager
 */
trait ValidatesDatabaseParameters
{
    /**
     * Characters allowed in parameters.
     *
     * Used as the default allowlist for validateParameter(), which validates non-password
     * parameters such as database names or usernames.
     */
    protected static function allowedParameterCharacters(): string
    {
        return 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-';
    }

    /**
     * Characters allowed in filenames (SQLite databases).
     *
     * Includes dots to support file extensions (e.g. '.sqlite').
     */
    protected static function allowedFilenameCharacters(): string
    {
        return 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-.';
    }

    /**
     * Characters allowed in database user passwords.
     *
     * Passwords are always quoted in the SQL statements, so it's safe
     * to allow a wider range of characters, as long as it doesn't include
     * characters that can break out of the quoted SQL strings (so e.g.
     * ', ", \, and ` aren't allowed).
     */
    protected static function allowedPasswordCharacters(): string
    {
        return ' !#$%&()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[]^_abcdefghijklmnopqrstuvwxyz{|}~';
    }

    /**
     * Ensure that parameters (database names, usernames, etc.)
     * only contain allowed characters before used in SQL statements
     * (or file names in the case of SQLiteDatabaseManager).
     *
     * By default, only the characters in static::allowedParameterCharacters() are allowed.
     *
     * Null parameters are skipped.
     *
     * @throws InvalidArgumentException
     */
    protected function validateParameter(string|array|null $parameters, string|null $allowedCharacters = null): void
    {
        $allowedCharacters ??= static::allowedParameterCharacters();

        foreach ((array) $parameters as $parameter) {
            if (is_null($parameter)) {
                // Skip if there's nothing to validate
                // (e.g. when $tenant->database()->getUsername() of an
                // improperly created tenant is null and it gets passed).
                continue;
            }

            if (! is_string($parameter)) {
                throw new InvalidArgumentException('Parameter has to be a string.');
            }

            foreach (str_split($parameter) as $character) {
                if (! str_contains($allowedCharacters, $character)) {
                    throw new InvalidArgumentException("Forbidden character '{$character}' in parameter.");
                }
            }
        }
    }

    /**
     * Ensure password only contains allowed characters (static::allowedPasswordCharacters())
     * before used in SQL statements.
     *
     * Used in permission controlled managers as a shorthand for calling validateParameter()
     * with the less strict allowlist to validate database user passwords.
     *
     * @throws InvalidArgumentException
     */
    protected function validatePassword(string|null $password): void
    {
        $this->validateParameter($password, static::allowedPasswordCharacters());
    }

    /**
     * Ensure filename only contains allowed characters (static::allowedFilenameCharacters())
     * and is not a directory name before used in file paths (e.g. SQLite database names).
     *
     * @throws InvalidArgumentException
     * @see Stancl\Tenancy\Database\TenantDatabaseManagers\SQLiteDatabaseManager
     */
    protected function validateFilename(string $filename): void
    {
        $this->validateParameter($filename, static::allowedFilenameCharacters());

        if ($filename === '') {
            throw new InvalidArgumentException("Filename cannot be empty.");
        }

        if (is_dir($filename)) {
            throw new InvalidArgumentException("Filename ('{$filename}') cannot be a directory.");
        }
    }
}
