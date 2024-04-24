<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Exceptions;

use Exception;

/**
 * @see \Stancl\Tenancy\RLS\PolicyManagers\TableRLSManager
 */
class RecursiveRelationshipException extends Exception
{
    public function __construct(string|null $message = null)
    {
        parent::__construct($message ?? "Table's foreign key referenced multiple times in the same path.");
    }
}
