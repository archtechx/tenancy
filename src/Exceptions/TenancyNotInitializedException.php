<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Exceptions;

use Exception;

class TenancyNotInitializedException extends Exception
{
    public function __construct(string $message = '')
    {
        parent::__construct($message ?: 'Tenancy is not initialized.');
    }
}
