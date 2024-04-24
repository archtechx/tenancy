<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Exceptions;

use Exception;

/**
 * @see \Stancl\Tenancy\RLS\PolicyManagers\TraitRLSManager
 */
class TableNotRelatedToTenantException extends Exception
{
    public function __construct(string $table)
    {
        parent::__construct("Table $table does not belong to a tenant directly or through another table.");
    }
}
