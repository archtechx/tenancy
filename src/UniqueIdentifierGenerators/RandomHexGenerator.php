<?php

declare(strict_types=1);

namespace Stancl\Tenancy\UniqueIdentifierGenerators;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Contracts\UniqueIdentifierGenerator;

/**
 * Generates a cryptographically secure random hex string for the tenant key.
 *
 * To customize the byte length, change the static `bytes` property.
 * The produced string length is 2 * byte length.
 * The number of unique combinations is 2 ^ (8 * byte length).
 */
class RandomHexGenerator implements UniqueIdentifierGenerator
{
    public static int $bytes = 6;

    public static function generate(Model $model): string|int
    {
        return bin2hex(random_bytes(static::$bytes));
    }
}
