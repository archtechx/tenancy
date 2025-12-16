<?php

declare(strict_types=1);

namespace Stancl\Tenancy\UniqueIdentifierGenerators;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Stancl\Tenancy\Contracts\UniqueIdentifierGenerator;

/**
 * Generates a UUIDv7 for the tenant key.
 */
class UUIDv7Generator implements UniqueIdentifierGenerator
{
    public static function generate(Model $model): string|int
    {
        return Str::uuid7()->toString();
    }
}
