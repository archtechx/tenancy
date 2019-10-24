<?php

namespace Stancl\Tenancy\Exceptions;

use Exception;

class NotImplementedException extends Exception
{
    public function __construct($class, $method, $extra)
    {
        parent::__construct("The $class class does not implement the $method method. $extra");
    }
}