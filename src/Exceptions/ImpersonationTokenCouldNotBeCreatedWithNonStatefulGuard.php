<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Exceptions;

use Exception;

class ImpersonationTokenCouldNotBeCreatedWithNonStatefulGuard extends Exception
{
    public function __construct(string $guardName)
    {
        parent::__construct("Cannot use a non-stateful auth guard ('$guardName') with user impersonation. Use a guard implementing the Illuminate\Contracts\Auth\StatefulGuard interface.");
    }
}
