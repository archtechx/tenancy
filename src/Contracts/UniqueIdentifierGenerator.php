<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts;

interface UniqueIdentifierGenerator
{
    /**
     * Generate a unique identifier.
     *
     * @param string[] $domains
     * @param array $data
     * @return string
     */
    public static function generate(array $domains, array $data = []): string;
}
