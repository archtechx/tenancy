<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts;

use Illuminate\Database\Eloquent\Model;

interface UniqueIdentifierGenerator
{
    /**
     * Generate a unique identifier for a model.
     */
    public static function generate(Model $model): string|int;
}
