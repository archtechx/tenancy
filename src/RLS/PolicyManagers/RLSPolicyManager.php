<?php

declare(strict_types=1);

namespace Stancl\Tenancy\RLS\PolicyManagers;

interface RLSPolicyManager
{
    /**
     * Generate queries that create row-level security policies for tables.
     *
     * @return string[]
     */
    public function generateQueries(): array;
}
