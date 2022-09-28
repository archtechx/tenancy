<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Exceptions;

use Exception;

class DatabaseManagerNotRegisteredException extends Exception
{
    public function __construct(string $driver)
    {
        parent::__construct("Database manager for driver $driver is not registered.");
    }
}
