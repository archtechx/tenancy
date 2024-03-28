<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Contracts\UniqueIdentifierGenerator;

// todo@deprecation remove after 2024-04-12
class UUIDGenerator implements UniqueIdentifierGenerator
{
    public static function generate(Model $model): string
    {
        throw new Exception('Tenancy update note: UUIDGenerator has been renamed to Stancl\Tenancy\UniqueIdentifierGenerators\UUIDGenerator. Please update your config/tenancy.php');
    }
}
