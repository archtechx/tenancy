<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Exceptions;

use Exception;

class TenantDoesNotExistException extends Exception
{
    public function __construct(string $id, string $key = 'id')
    {
        $this->message = "Tenant with this $key does not exist: $id";
    }
}
