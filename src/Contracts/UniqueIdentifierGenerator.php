<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts;

interface UniqueIdentifierGenerator
{
    /**
     * Generate a unique identifier.
     *
     * @param string $domain
     * @param array $data
     * @return string
     */
    public static function handle(string $domain, array $data = []): string;
}
