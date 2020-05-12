<?php

declare(strict_types=1);

namespace Stancl\Tenancy\UniqueIDGenerators;

use Ramsey\Uuid\Uuid;
use Stancl\Tenancy\Contracts\UniqueIdentifierGenerator;

class UUIDGenerator implements UniqueIdentifierGenerator
{
    public static function generate($resource): string
    {
        return Uuid::uuid4()->toString();
    }
}
