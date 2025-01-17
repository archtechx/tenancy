<?php

declare(strict_types=1);

namespace Stancl\Tenancy\UniqueIdentifierGenerators;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Contracts\UniqueIdentifierGenerator;

/**
 * Generates a cryptographically secure random integer for the tenant key.
 *
 * The integer is generated in range (0, PHP_INT_MAX).
 */
class RandomIntGenerator implements UniqueIdentifierGenerator
{
    public static function generate(Model $model): string|int
    {
        return random_int(0, PHP_INT_MAX);
    }
}
