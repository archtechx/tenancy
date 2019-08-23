<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Interfaces;

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
