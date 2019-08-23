<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Stancl\Tenancy\Interfaces\UniqueIdentifierGenerator;

class UUIDGenerator implements UniqueIdentifierGenerator
{
    public static function handle(string $domain, array $data = []): string
    {
        return (string) \Webpatser\Uuid\Uuid::generate(1, $domain);
    }
}
