<?php

declare(strict_types=1);

namespace Stancl\Tenancy\UniqueIDGenerators;

use Ramsey\Uuid\Uuid;
use Stancl\Tenancy\Contracts\UniqueIdentifierGenerator;
use Stancl\Tenancy\Database\Models\Tenant;

class UUIDGenerator implements UniqueIdentifierGenerator
{
    public static function generate(Tenant $tenant): string
    {
        return Uuid::uuid4()->toString();
    }
}
