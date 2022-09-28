<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Exceptions;

use Exception;

class TenantDatabaseDoesNotExistException extends Exception
{
    public function __construct(string $database)
    {
        parent::__construct("Database $database does not exist.");
    }
}
