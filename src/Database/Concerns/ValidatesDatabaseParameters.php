<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use InvalidArgumentException;

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
     * Validate that parameters (database names, usernames, etc.)
     * only contain allowed characters before used in SQL statements.
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
     * Validate that a password only contains allowed characters before used in SQL statements.
     *
     * Used as a shorthand for validateParameter() with the less strict allowlist.
     *
     * @throws InvalidArgumentException
     */
    protected function validatePassword(string|null $password): string|null
    {
        return $this->validateParameter($password, static::passwordAllowlist());
    }
}
