<?php

declare(strict_types=1);

namespace Stancl\Tenancy\RLS\Exceptions;

use Exception;

class RLSPolicyNameTooLongException extends Exception
{
    public function __construct(string $table, string $policyName, int $limit, int $maxTableLength)
    {
        parent::__construct(
            "The RLS policy name for table '{$table}' would be longer than {$limit} bytes ('{$policyName}'), so Postgres would truncate it. " .
            "Names of tables scoped by RLS can be at most {$maxTableLength} bytes long."
        );
    }
}
