<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;
use Stancl\Tenancy\Contracts\UniqueIdentifierGenerator;

// todo move to separate namespace

class UUIDGenerator implements UniqueIdentifierGenerator
{
    public static function generate(Model $model): string
    {
        return Uuid::uuid4()->toString();
    }
}
