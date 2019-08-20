<?php

namespace Stancl\Tenancy\Exceptions;

class DatabaseManagerNotRegisteredException extends \Exception
{
    public function __construct($error, $driver)
    {
        $this->message = "$error: no database manager for driver $driver is registered.";
    }
}
