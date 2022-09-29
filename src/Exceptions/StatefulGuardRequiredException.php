<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Exceptions;

use Exception;

class StatefulGuardRequiredException extends Exception
{
    public function __construct(string $guardName)
    {
        parent::__construct("Cannot use a non-stateful guard ('$guardName'). A guard implementing the Illuminate\\Contracts\\Auth\\StatefulGuard interface is required.");
    }
}
