<?php

declare(strict_types=1);

namespace Stancl\Tenancy\UniqueIdentifierGenerators;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Contracts\UniqueIdentifierGenerator;

/**
 * Generates a cryptographically secure random integer for the tenant key.
 */
class RandomIntGenerator implements UniqueIdentifierGenerator
{
    public static int $min = 0;
    public static int $max = PHP_INT_MAX;

    public static function generate(Model $model): string|int
    {
        return random_int(static::$min, static::$max);
    }
}
