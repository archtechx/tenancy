<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Stancl\Tenancy\Contracts\UniqueIdentifierGenerator;

class UUIDGenerator implements UniqueIdentifierGenerator
{
    public static function generate(array $domains, array $data = []): string
    {
        return (string) \Webpatser\Uuid\Uuid::generate(1, $domains[0] ?? '');
    }
}
