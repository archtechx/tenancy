<?php

declare(strict_types=1);

namespace Stancl\Tenancy\UniqueIdentifierGenerators;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Stancl\Tenancy\Contracts\UniqueIdentifierGenerator;

/**
 * Generates a cryptographically secure random string for the tenant key.
 *
 * To customize the string length, change the static `$length` property.
 * The number of unique combinations is 61 ^ string length.
 */
class RandomStringGenerator implements UniqueIdentifierGenerator
{
    public static int $length = 8;

    public static function generate(Model $model): string|int
    {
        return Str::random(static::$length);
    }
}
